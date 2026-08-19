import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { InlineConfirm } from '@/components/vault';
import { cn } from '@/lib/utils';
import type { ProjectVariable } from '../types';

interface Version {
    id: number;
    version: number;
    by: string;
    at: string | null;
    current: boolean;
}

/**
 * The change history for one value, and the way back to an earlier one.
 *
 * It lists WHO changed it and WHEN, never what to - seeing that a value moved
 * is a smaller thing than seeing the value, so this does not require a reveal.
 * A rollback writes the old value forward as a new version; history is never
 * rewritten.
 */
export function HistoryDialog({
    variable,
    env,
    basePath,
    onClose,
}: {
    variable: ProjectVariable;
    env: string;
    basePath: string;
    onClose: () => void;
}) {
    const [versions, setVersions] = useState<Version[] | null>(null);
    const [refused, setRefused] = useState(false);

    // A rollback is consequential but not destructive - it writes the old
    // value forward as a new version. An inline confirm fits; a red dialog
    // listing what is destroyed would be lying.
    const [confirming, setConfirming] = useState<number | null>(null);
    // In-flight guard: a rollback creates a version, so a double-click must not
    // create two.
    const [rolling, setRolling] = useState(false);

    const url = `${basePath}/variables/${variable.id}/environments/${env}`;

    useEffect(() => {
        let cancelled = false;

        fetch(`${url}/history`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (cancelled) {
                    return;
                }

                if (!response.ok) {
                    setRefused(true);

                    return;
                }

                const body = await response.json();
                setVersions(body.versions ?? []);
            })
            .catch(() => !cancelled && setRefused(true));

        return () => {
            cancelled = true;
        };
    }, [url]);

    const rollback = (version: Version) => {
        if (rolling) {
            return;
        }

        router.post(
            `${url}/rollback/${version.id}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setRolling(true),
                onSuccess: () => {
                    toast.success(`${variable.key} rolled back · logged`);
                    onClose();
                },
                onError: () => toast.error('That rollback was refused.'),
                onFinish: () => setRolling(false),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="w-[420px] border-line-2 bg-panel p-0">
                <DialogHeader className="space-y-0 border-b border-line px-[17px] py-[15px] text-left">
                    <DialogTitle className="text-[13px] font-semibold">
                        {variable.key} in {env}
                    </DialogTitle>
                    <DialogDescription className="mt-0.5 text-[11px] text-fg-3">
                        Who changed it and when. A rollback is itself a change.
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] overflow-auto">
                    {refused ? (
                        <div className="px-[17px] py-4 text-[12.5px] text-fg-2">
                            You cannot roll back values in {env}.
                        </div>
                    ) : versions === null ? (
                        <div className="px-[17px] py-4 text-[12.5px] text-fg-3">
                            Loading…
                        </div>
                    ) : versions.length === 0 ? (
                        <div className="px-[17px] py-4 text-[12.5px] text-fg-3">
                            No history yet - this value has never changed.
                        </div>
                    ) : (
                        versions.map((version) => (
                            <div
                                key={version.id}
                                className="border-b border-line px-[17px] py-2.5"
                            >
                                <div className="flex items-center gap-2.5">
                                    <span className="font-mono text-[11.5px] text-fg-3">
                                        v{version.version}
                                    </span>
                                    <div className="min-w-0">
                                        <div className="truncate text-[12.5px]">
                                            {version.by}
                                        </div>
                                        <div className="text-[11px] text-fg-3">
                                            {version.at}
                                        </div>
                                    </div>
                                    {version.current ? (
                                        <span className="ml-auto rounded-full bg-pub-soft px-2 py-0.5 text-[10.5px] text-pub">
                                            current
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setConfirming(version.id)
                                            }
                                            className={cn(
                                                'ml-auto h-[23px] cursor-pointer rounded-md border border-line-2 bg-transparent px-2 text-[10.5px] text-fg-2',
                                                'transition-colors hover:bg-panel-3 hover:text-fg',
                                            )}
                                        >
                                            Restore
                                        </button>
                                    )}
                                </div>

                                {confirming === version.id && (
                                    <InlineConfirm
                                        className="mt-2"
                                        message={`Roll back to v${version.version}? It becomes a new version.`}
                                        confirmLabel="Roll back"
                                        onConfirm={() => rollback(version)}
                                        onCancel={() => setConfirming(null)}
                                    />
                                )}
                            </div>
                        ))
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
