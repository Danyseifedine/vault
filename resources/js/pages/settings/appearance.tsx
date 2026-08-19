import { Head } from '@inertiajs/react';
import { Segmented } from '@/components/vault';
import { useAppearance } from '@/hooks/use-appearance';
import AccountLayout, { AccountSection } from '@/layouts/account-layout';

export default function Appearance() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <AccountSection
            title="Appearance"
            description="Dark is the default. Sign-in and the rest of the unauthenticated screens are always dark, whatever you pick here."
        >
            <Head title="Appearance settings" />

            <div className="rounded-[14px] border border-line bg-panel p-5">
                <div className="mb-3 text-[13px] font-semibold">Theme</div>
                <Segmented
                    options={[
                        { value: 'dark', label: 'Dark' },
                        { value: 'light', label: 'Light' },
                        { value: 'system', label: 'System' },
                    ]}
                    value={appearance}
                    onChange={(next) =>
                        updateAppearance(next as 'dark' | 'light' | 'system')
                    }
                />
                <p className="mt-3 text-[11.5px] text-fg-3">
                    Stored on this device, and in a cookie so the first paint
                    after a reload already matches.
                </p>
            </div>
        </AccountSection>
    );
}

Appearance.layout = (page: React.ReactNode) => (
    <AccountLayout active="appearance">{page}</AccountLayout>
);
