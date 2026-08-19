import { Head } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import { ChoiceSection } from './partials/choice-section';
import { DisplaySection } from './partials/display-section';
import { FeedbackSections } from './partials/feedback-section';
import { InputsSections } from './partials/inputs-section';
import { UploadSection } from './partials/upload-section';

/**
 * The living component library, at /components.
 *
 * Every control the dashboard uses, interactive, on the same tokens as the
 * app. Each section is a partial that owns its own demo state - this page is
 * only the frame around them.
 */
export default function Components() {
    const { resolvedAppearance, updateAppearance } = useAppearance();

    return (
        <div className="min-h-screen bg-shell font-sans text-[13.5px] text-fg antialiased">
            <Head title="Component library" />

            <main className="mx-auto my-2 max-w-5xl rounded-xl border border-line bg-background">
                <div className="flex items-start border-b border-line px-8 pt-8 pb-5">
                    <div>
                        <h1 className="mb-2 text-[26px] font-semibold tracking-tight">
                            The Vault - component library
                        </h1>
                        <p className="max-w-[620px] text-sm text-pretty text-fg-2">
                            Every control the dashboard uses, live and
                            interactive. Same tokens as the app: one accent,
                            three sensitivity colors, four surfaces.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            updateAppearance(
                                resolvedAppearance === 'dark'
                                    ? 'light'
                                    : 'dark',
                            )
                        }
                        className="ml-auto grid size-8 cursor-pointer place-items-center rounded-lg border border-line bg-transparent text-fg-2 transition-colors hover:bg-panel-2 hover:text-fg"
                        aria-label="Toggle theme"
                    >
                        {resolvedAppearance === 'dark' ? (
                            <Sun className="size-4" strokeWidth={1.75} />
                        ) : (
                            <Moon className="size-4" strokeWidth={1.75} />
                        )}
                    </button>
                </div>

                <InputsSections />
                <ChoiceSection />
                <UploadSection />
                <DisplaySection />
                <FeedbackSections />
            </main>
        </div>
    );
}
