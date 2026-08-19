import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    AlertBanner,
    InlineConfirm,
    PillTabs,
    UnderlineTabs,
} from '@/components/vault';
import { Field, Panel, Section } from './parts';

/** Banners, toasts, confirms - and the ways between screens. */
export function FeedbackSections() {
    const [confirming, setConfirming] = useState(true);
    const [tab, setTab] = useState('assigned');
    const [utab, setUtab] = useState('variables');

    return (
        <>
            <Section title="Feedback">
                <Panel>
                    <AlertBanner tone="critical" title="Reveals locked">
                        3 failed PIN attempts. Try again in 15 minutes.
                    </AlertBanner>
                    <AlertBanner
                        tone="warning"
                        title="Changing this breaks things"
                    >
                        4 apps consume this key in prod.
                    </AlertBanner>
                    <AlertBanner tone="success" title="Chain verified">
                        14,802 audit entries, no breaks.
                    </AlertBanner>
                    <AlertBanner tone="info" title="Invite pending">
                        lea@acme.co - expires in 6 days.
                    </AlertBanner>
                </Panel>
                <Panel>
                    <Field label="Toast">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => toast.success('Copied · logged')}
                        >
                            Trigger toast
                        </Button>
                    </Field>
                    <Field label="Inline confirm">
                        {confirming ? (
                            <InlineConfirm
                                message={
                                    <>
                                        Delete{' '}
                                        <span className="font-mono">
                                            SMTP_PASSWORD
                                        </span>
                                        ?
                                    </>
                                }
                                onConfirm={() => {
                                    setConfirming(false);
                                    toast.success('Deleted · logged');
                                }}
                                onCancel={() => setConfirming(false)}
                            />
                        ) : (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setConfirming(true)}
                            >
                                Reset example
                            </Button>
                        )}
                    </Field>
                </Panel>
            </Section>

            <Section title="Navigation">
                <Panel>
                    <Field label="Breadcrumb">
                        <div className="flex items-center gap-[7px] text-[12.5px] text-fg-3">
                            <span>acme</span>
                            <span className="opacity-50">/</span>
                            <span>lebify</span>
                            <span className="opacity-50">/</span>
                            <span className="font-mono text-fg">prod</span>
                        </div>
                    </Field>
                    <Field label="Pill tabs">
                        <PillTabs
                            options={[
                                { value: 'assigned', label: 'Assigned' },
                                { value: 'all', label: 'All variables' },
                                { value: 'history', label: 'History' },
                                { value: 'settings', label: 'Settings' },
                            ]}
                            value={tab}
                            onChange={setTab}
                        />
                    </Field>
                    <Field label="Underline tabs">
                        <UnderlineTabs
                            options={[
                                { value: 'variables', label: 'Variables' },
                                { value: 'audit', label: 'Audit log' },
                                { value: 'members', label: 'Members' },
                            ]}
                            value={utab}
                            onChange={setUtab}
                        />
                    </Field>
                </Panel>
            </Section>
        </>
    );
}
