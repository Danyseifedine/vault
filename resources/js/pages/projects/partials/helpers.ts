import type {
    EnvironmentAbilities,
    ProjectVariable,
    RevealRequirement,
} from '../types';

/**
 * What revealing THIS variable costs in the environment on screen.
 *
 * The sensitivity label only picks a row of the environment's matrix; the
 * value in that row is whatever project settings say here. Reading the label
 * alone is how a dev set to "no PIN" ended up with buttons saying PIN and a
 * dialog asking for one the server was never going to check.
 *
 * Falls back to the strictest reading when no policy travelled - guessing
 * "free" would be the one wrong way to guess.
 */
export function requirementFor(
    variable: ProjectVariable,
    abilities?: EnvironmentAbilities,
): RevealRequirement {
    return abilities?.policies?.[variable.sensitivity] ?? 'pin_password';
}

/** How that cost reads on a button. */
export function revealLabel(requirement: RevealRequirement): string {
    return requirement === 'pin_password'
        ? 'PIN + pw'
        : requirement === 'pin'
          ? 'PIN'
          : 'Show';
}

/** Variables that have a value defined in the given environment. */
export function variablesIn(
    variables: ProjectVariable[],
    env: string,
): ProjectVariable[] {
    return variables.filter((v) => v.values[env] !== undefined);
}

export function valueFor(
    variable: ProjectVariable,
    env: string,
): string | undefined {
    return variable.values[env];
}
