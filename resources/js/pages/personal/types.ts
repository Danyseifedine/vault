/** Metadata only - a personal listing never carries a value either. */
export interface PersonalItem {
    id: number;
    groupId: number | null;
    type: 'secret' | 'file';
    name: string;
    description: string | null;
    updatedAt: string | null;
}

export interface PersonalGroup {
    id: number;
    name: string;
}

export interface PersonalPageProps {
    groups: PersonalGroup[];
    items: PersonalItem[];
    activity: {
        action: string;
        name: string | null;
        failed: boolean;
        when: string;
    }[];
}
