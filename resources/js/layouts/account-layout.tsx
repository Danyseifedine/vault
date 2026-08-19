import { router, usePage } from '@inertiajs/react';
import { Palette, ShieldCheck, User, Vault } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    AppShell,
    ShellHeader,
    SidebarBrand,
    SidebarFooter,
    SidebarHeading,
    SidebarMonoRow,
    SidebarNav,
} from '@/components/vault';
import type { NavItem } from '@/components/vault';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { index as personalVault } from '@/routes/personal';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

export type AccountScreen = 'personal' | 'profile' | 'security' | 'appearance';

const NAV: (NavItem & { id: AccountScreen; href: string })[] = [
    {
        id: 'personal',
        label: 'Personal vault',
        icon: Vault,
        href: personalVault().url,
    },
    { id: 'profile', label: 'Profile', icon: User, href: editProfile().url },
    {
        id: 'security',
        label: 'Security',
        icon: ShieldCheck,
        href: editSecurity().url,
    },
    {
        id: 'appearance',
        label: 'Appearance',
        icon: Palette,
        href: editAppearance().url,
    },
];

const TITLES: Record<AccountScreen, string> = {
    personal: 'Personal vault',
    profile: 'Profile',
    security: 'Security',
    appearance: 'Appearance',
};

/**
 * Everything that belongs to you rather than to an organization: your own
 * vault, your profile, your password and second factor.
 *
 * It wears the same frame as the rest of the product. These pages used to sit
 * in the starter kit's layout, which made a corner of the application look like
 * a different application.
 */
export default function AccountLayout({
    active,
    children,
}: {
    active: AccountScreen;
    children: ReactNode;
}) {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    const sidebar = (
        <>
            <SidebarBrand context="you" />
            <SidebarNav
                items={NAV.map(({ id, label, icon }) => ({ id, label, icon }))}
                active={active}
                onSelect={(id) =>
                    router.visit(NAV.find((item) => item.id === id)!.href)
                }
            />
            <SidebarHeading>Elsewhere</SidebarHeading>
            <div className="flex flex-col gap-px px-2.5">
                <SidebarMonoRow
                    name="All organizations"
                    href={dashboard().url}
                />
            </div>
            <SidebarFooter
                name={auth.user.name}
                meta={auth.user.email}
                initials={getInitials(auth.user.name)}
            />
        </>
    );

    return (
        <AppShell sidebar={sidebar}>
            <ShellHeader crumb="you" active={TITLES[active]} />
            <div className="flex-1 overflow-auto">{children}</div>
        </AppShell>
    );
}

/** The page wrapper inside the frame: heading, description, then the content. */
export function AccountSection({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <div className="w-full max-w-[560px] px-4 pt-[20px] pb-12 sm:px-7 sm:pt-[26px]">
            <h1 className="mb-1.5 text-xl font-semibold tracking-tight">
                {title}
            </h1>
            <p className="mb-[22px] text-[13px] text-pretty text-fg-2">
                {description}
            </p>
            {children}
        </div>
    );
}
