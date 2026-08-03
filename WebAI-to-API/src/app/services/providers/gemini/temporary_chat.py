import asyncio
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Awaitable, Callable

from fastapi import HTTPException
from fastapi.responses import StreamingResponse

from app.config import CONFIG
from app.logger import logger
from app.schemas.request import OpenAIChatRequest
from app.services.gemini_client import GeminiClientNotInitializedError, get_gemini_client
from app.services.providers.gemini.webapi_client import MyGeminiClient
from app.services.multimodal import (
    NormalizedOpenAIChatMessages,
    cleanup_staged_files,
    normalize_openai_chat_messages,
)
from app.services.providers.gemini.shared import (
    build_tools_prompt,
    convert_to_openai_format,
    parse_tool_call,
    validate_model_name,
)
from app.services.providers.gemini.session_manager import transform_messages
from app.services.providers.gemini.webapi_response_builder import (
    build_webapi_chat_completion_response,
    build_webapi_streaming_artifact_chunk,
)
from app.utils.streaming import format_sse_chunk, get_done_chunk, simulate_streaming_generator


@dataclass(slots=True)
class TemporaryChatRequestContext:
    model: str
    normalized: NormalizedOpenAIChatMessages
    prompt: str
    files: list[Path] | None
    is_stream: bool
    tools: list[dict[str, Any]] | None
    gem: str | None


def _resolve_temporary_chat_model(request: OpenAIChatRequest) -> str:
    if not request.messages:
        raise HTTPException(status_code=400, detail="No messages provided.")

    if request.conversation_id is not None:
        raise HTTPException(
            status_code=400,
            detail="conversation_id is not supported on the temporary chat endpoint.",
        )

    provider = (request.provider or "").strip().lower()
    if provider and provider != "gemini":
        raise HTTPException(
            status_code=400,
            detail="Only the Gemini provider is supported on the temporary chat endpoint.",
        )

    model = request.model or CONFIG["Gemini"].get("default_model", "gemini-3-flash")
    model = model.strip()

    if model.startswith("playwright/"):
        raise HTTPException(
            status_code=400,
            detail="Playwright models are not supported on the temporary chat endpoint.",
        )

    if model.startswith("atlas/"):
        raise HTTPException(
            status_code=400,
            detail="Atlas models are not supported on the temporary chat endpoint.",
        )

    if model.startswith("gemini/"):
        model = model.split("/", 1)[1].strip()

    validate_model_name(model)
    return model


def _streaming_headers() -> dict[str, str]:
    return {
        "Cache-Control": "no-cache",
        "Connection": "keep-alive",
        "X-Accel-Buffering": "no",
    }


def _prepare_temporary_chat_request(request: OpenAIChatRequest) -> TemporaryChatRequestContext:
    model = _resolve_temporary_chat_model(request)
    normalized = normalize_openai_chat_messages(request.messages, allow_file_parts=True)
    tools_prompt = build_tools_prompt(request.tools) if request.tools else ""
    prompt = "\n\n".join(transform_messages(normalized.messages, tools_prompt))
    return TemporaryChatRequestContext(
        model=model,
        normalized=normalized,
        prompt=prompt,
        files=normalized.files or None,
        is_stream=request.stream if request.stream is not None else False,
        tools=request.tools,
        gem=request.gem,
    )


def _build_cleanup_once(
    normalized: NormalizedOpenAIChatMessages,
) -> Callable[[], Awaitable[None]]:
    cleanup_started = False

    async def cleanup_once() -> None:
        nonlocal cleanup_started
        if cleanup_started:
            return
        cleanup_started = True
        await cleanup_staged_files(normalized)

    return cleanup_once


def _build_streaming_compatibility_response(openai_response: dict) -> StreamingResponse:
    # Tool requests currently use buffered SSE compatibility mode rather than fully
    # incremental tool-aware streaming, so the buffered response is replayed as SSE.
    return StreamingResponse(
        simulate_streaming_generator(openai_response),
        media_type="text/event-stream",
        headers=_streaming_headers(),
    )


async def _build_buffered_openai_response(
    gemini_client,
    *,
    prompt: str,
    model: str,
    files: list[Path] | None,
    gem: str | None,
    tools: list[dict[str, Any]] | None,
) -> dict:
    response = await gemini_client.generate_content(
        prompt,
        model,
        files=files,
        gem=gem,
        temporary=True,
    )
    response_text = getattr(response, "text", "") or ""
    tool_call = parse_tool_call(response_text) if tools else None
    return build_webapi_chat_completion_response(
        response,
        model,
        tool_call=tool_call,
    )


async def _build_incremental_streaming_response(
    gemini_client,
    *,
    prompt: str,
    model: str,
    files: list[Path] | None,
    gem: str | None,
    cleanup_once: Callable[[], Awaitable[None]],
) -> StreamingResponse:
    async def sse_generator():
        final_response = None
        try:
            stream = await gemini_client.generate_content_stream(
                prompt,
                model,
                files=files,
                gem=gem,
                temporary=True,
            )
            async for chunk in stream:
                final_response = chunk
                text_delta = getattr(chunk, "text_delta", "")
                if text_delta:
                    openai_chunk = convert_to_openai_format(text_delta, model, stream=True)
                    yield await format_sse_chunk(openai_chunk)

            if final_response is not None:
                artifact_chunk = build_webapi_streaming_artifact_chunk(final_response, model)
                if artifact_chunk is not None:
                    artifact_chunk.pop("conversation_id", None)
                    artifact_chunk.pop("reused_conversation", None)
                    yield await format_sse_chunk(artifact_chunk)
        except (asyncio.CancelledError, GeneratorExit):
            raise
        except Exception as e:
            logger.error(
                f"Error in /v1/temporary/chat/completions progressive streaming: {e}",
                exc_info=True,
            )
            raise
        else:
            yield await get_done_chunk()
        finally:
            await cleanup_once()

    return StreamingResponse(
        sse_generator(),
        media_type="text/event-stream",
        headers=_streaming_headers(),
    )


# --- LOCAL PATCH (not upstream) ---
# See the matching comment in app/endpoints/chat.py. This builds a one-off
# Gemini WebAPI client from request-supplied cookies instead of using the
# process-wide singleton from app.services.gemini_client — deliberately only
# hooked into this stateless/no-persistence path, not the main
# SessionRegistry-backed /v1/chat/completions flow, which has no per-request
# client seam (its SessionManager/SessionRegistry hold one shared client for
# the whole process — see session_manager.py).
async def _build_ephemeral_gemini_client(override: dict) -> MyGeminiClient:
    proxy = CONFIG["Proxy"].get("http_proxy") or None
    client = MyGeminiClient(secure_1psid=override["psid"], secure_1psidts=override["psidts"], proxy=proxy)

    try:
        await client.init(verbose=True, auto_refresh=False)
    except Exception as e:
        await client.close()
        raise HTTPException(status_code=401, detail=f"Gemini cookie override rejected: {e}") from e

    account_status = getattr(client.client, "account_status", None)
    status_name = getattr(account_status, "name", "UNKNOWN") if account_status else "UNKNOWN"
    if status_name != "AVAILABLE":
        await client.close()
        raise HTTPException(
            status_code=401,
            detail=f"Gemini cookie override is not usable (status: {status_name}).",
        )

    return client


async def _resolve_gemini_client(request: OpenAIChatRequest) -> tuple[MyGeminiClient, bool]:
    """Returns (client, is_ephemeral) — ephemeral clients must be closed by the caller."""
    override = getattr(request, "_gemini_cookie_override", None)
    if override is not None:
        return await _build_ephemeral_gemini_client(override), True

    try:
        return get_gemini_client(), False
    except GeminiClientNotInitializedError as e:
        raise HTTPException(status_code=503, detail=str(e))
# --- END LOCAL PATCH ---


async def handle_temporary_chat_completions(request: OpenAIChatRequest):
    gemini_client, is_ephemeral_client = await _resolve_gemini_client(request)

    prepared = _prepare_temporary_chat_request(request)
    cleanup_once = _build_cleanup_once(prepared.normalized)

    # LOCAL PATCH: closing the ephemeral client needs different timing per
    # branch, so it's deliberately NOT folded into this function's own
    # `finally` (unlike cleanup_once, which correctly no-ops there since the
    # streaming branch already re-invokes it from inside the generator).
    # `return await _build_incremental_streaming_response(...)` below only
    # awaits *construction* of the StreamingResponse — the generator inside
    # it runs later, lazily, once the ASGI server starts consuming it. If
    # this function's own `finally` closed the client, that would happen
    # immediately on return, before the generator ever calls
    # `generate_content_stream` — killing the connection out from under it.
    # So: streaming branch closes from inside the generator's own `finally`
    # (via close_ephemeral_client, passed in below); the buffered branch
    # closes right after its one blocking call completes.
    async def close_ephemeral_client() -> None:
        if is_ephemeral_client:
            await gemini_client.close()

    try:
        if prepared.is_stream and not prepared.tools:
            async def finalize_stream() -> None:
                await cleanup_once()
                await close_ephemeral_client()

            return await _build_incremental_streaming_response(
                gemini_client,
                prompt=prepared.prompt,
                model=prepared.model,
                files=prepared.files,
                gem=prepared.gem,
                cleanup_once=finalize_stream,
            )

        try:
            openai_response = await _build_buffered_openai_response(
                gemini_client,
                prompt=prepared.prompt,
                model=prepared.model,
                files=prepared.files,
                gem=prepared.gem,
                tools=prepared.tools,
            )
        finally:
            await close_ephemeral_client()

        if prepared.is_stream:
            return _build_streaming_compatibility_response(openai_response)
        return openai_response
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error in /v1/temporary/chat/completions endpoint: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Error generating temporary content: {str(e)}")
    finally:
        await cleanup_once()
