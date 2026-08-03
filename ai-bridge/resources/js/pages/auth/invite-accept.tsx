import { Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { api, ApiError } from '@/lib/console-api';

type Invite = {
    token: string;
    tenant_name: string;
    role: string;
    email: string | null;
    expired: boolean;
    used: boolean;
} | null;

export default function InviteAccept({ invite }: { invite: Invite }) {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    if (!invite || invite.expired || invite.used) {
        return (
            <>
                <Head title="Invite" />
                <div className="flex flex-col gap-4 text-center">
                    <h1 className="text-xl font-semibold">
                        {!invite ? 'Invite not found' : invite.used ? 'Invite already used' : 'Invite expired'}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {!invite
                            ? "This invite link doesn't exist."
                            : invite.used
                              ? 'This invite has already been used to create an account.'
                              : 'This invite link has expired. Ask whoever invited you to send a new one.'}
                    </p>
                    <a href="/login" className="text-sm underline">
                        Go to login
                    </a>
                </div>
            </>
        );
    }

    // TypeScript can't narrow a prop across the closure boundary below (it's
    // conservative about params that could theoretically change), even
    // though the guard clause above already guarantees this. Rebinding to a
    // const gives `submit` a properly non-null reference to close over.
    const activeInvite = invite;

    async function submit() {
        setProcessing(true);
        setErrors({});

        try {
            await api(`/invites/${activeInvite.token}/accept`, {
                method: 'POST',
                body: JSON.stringify({
                    name,
                    email: activeInvite.email ?? email,
                    password,
                    password_confirmation: passwordConfirmation,
                }),
            });
            window.location.href = '/dashboard';
        } catch (e) {
            if (e instanceof ApiError && typeof e.body === 'object' && e.body && 'errors' in e.body) {
                const fieldErrors = (e.body as { errors: Record<string, string[]> }).errors;
                setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])));
            } else {
                setErrors({ name: e instanceof ApiError ? e.message : 'Something went wrong.' });
            }
        } finally {
            setProcessing(false);
        }
    }

    return (
        <>
            <Head title="Accept invite" />
            <div className="flex flex-col gap-6">
                <p className="text-muted-foreground text-sm">
                    You've been invited to join <b className="text-foreground">{invite.tenant_name}</b> as{' '}
                    <b className="text-foreground">{invite.role}</b>.
                </p>

                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={name} onChange={(e) => setName(e.target.value)} autoFocus placeholder="Full name" />
                        <InputError message={errors.name} />
                    </div>

                    {!invite.email && (
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <PasswordInput
                            id="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            placeholder="Password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <PasswordInput
                            id="password_confirmation"
                            value={passwordConfirmation}
                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                            placeholder="Confirm password"
                        />
                    </div>

                    <Button
                        type="button"
                        className="mt-2 w-full"
                        disabled={processing || !name || !password}
                        onClick={submit}
                    >
                        {processing && <Spinner />}
                        Join {invite.tenant_name}
                    </Button>
                </div>
            </div>
        </>
    );
}

InviteAccept.layout = {
    title: 'Accept invite',
    description: 'Create your account to join the team',
};
