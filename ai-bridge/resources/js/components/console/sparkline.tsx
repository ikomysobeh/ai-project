export default function Sparkline({ points }: { points: number[] }) {
    const w = 100;
    const h = 42;
    const max = Math.max(...points, 1);
    const path = points.map((p, i) => `${(i / (points.length - 1 || 1)) * w},${h - (p / max) * h}`).join(' ');
    const area = `0,${h} ${path} ${w},${h}`;

    return (
        <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" style={{ width: '100%', height: 120, display: 'block' }}>
            <defs>
                <linearGradient id="console-sparkline-gradient" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#39d0d8" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#39d0d8" stopOpacity={0} />
                </linearGradient>
            </defs>
            <polygon points={area} fill="url(#console-sparkline-gradient)" />
            <polyline points={path} fill="none" stroke="#39d0d8" strokeWidth={1.2} vectorEffect="non-scaling-stroke" />
        </svg>
    );
}
