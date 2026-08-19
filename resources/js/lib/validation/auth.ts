import { z } from 'zod';

/**
 * Mirrors of the server's rules, one for one. The client
 * layer is UX - the Form Request always runs anyway - so when a rule moves on
 * one side it moves on the other in the same commit.
 */
const email = z
    .string()
    .min(1, 'Email is required.')
    .email('Enter a valid work email.');

/**
 * The production password rule (AppServiceProvider): 12+ characters with mixed
 * case, a number and a symbol. We mirror every check the client CAN run so a
 * weak password is refused in place instead of after a round trip; the one rule
 * only the server can enforce - `uncompromised`, a breach-database lookup -
 * stays there and surfaces as a server error on the rare hit.
 */
const newPassword = z
    .string()
    .min(12, 'Use at least 12 characters.')
    .regex(/[a-z]/, 'Include a lowercase letter.')
    .regex(/[A-Z]/, 'Include an uppercase letter.')
    .regex(/[0-9]/, 'Include a number.')
    .regex(/[^A-Za-z0-9]/, 'Include a symbol.');

/**
 * Signing IN only asserts the password exists - the length rule belongs to
 * creating one. The server's login rule is `required|string`; a stricter
 * client would lock out any account whose real password is shorter.
 */
export const loginSchema = z.object({
    email,
    password: z.string().min(1, 'Your password is required.'),
    remember: z.boolean().optional(),
});

export const forgotPasswordSchema = z.object({ email });

export const resetPasswordSchema = z
    .object({
        password: newPassword,
        password_confirmation: z.string().min(1, 'Confirm your new password.'),
    })
    .refine((values) => values.password === values.password_confirmation, {
        path: ['password_confirmation'],
        message: 'Both passwords must match.',
    });

/** A TOTP code is six digits - the one rule both OTP forms share. */
export const otpSchema = z.string().regex(/^\d{6}$/, 'The code is six digits.');

export const confirmPasswordSchema = z.object({
    password: z.string().min(1, 'Your password is required.'),
});

export const profileSchema = z.object({
    name: z
        .string()
        .min(1, 'Your name is required.')
        .max(255, 'That is longer than 255 characters.'),
});

export const changePasswordSchema = z
    .object({
        current_password: z
            .string()
            .min(1, 'Your current password is required.'),
        password: newPassword,
        password_confirmation: z.string().min(1, 'Confirm your new password.'),
    })
    .refine((values) => values.password === values.password_confirmation, {
        path: ['password_confirmation'],
        message: 'Both passwords must match.',
    });

export const onboardingPasswordSchema = z
    .object({
        password: newPassword,
        password_confirmation: z.string().min(1, 'Confirm your password.'),
    })
    .refine((values) => values.password === values.password_confirmation, {
        path: ['password_confirmation'],
        message: 'Both passwords must match.',
    });

export const onboardingProfileSchema = z.object({
    name: z
        .string()
        .min(1, 'Your full name is required.')
        .max(255, 'That is longer than 255 characters.'),
    job_title: z
        .string()
        .min(1, 'A job title is required.')
        .max(255, 'That is longer than 255 characters.'),
    stack: z.array(z.string().max(50)).max(20, 'Pick at most 20.'),
});

export type LoginValues = z.infer<typeof loginSchema>;
export type OnboardingPasswordValues = z.infer<typeof onboardingPasswordSchema>;
export type OnboardingProfileValues = z.infer<typeof onboardingProfileSchema>;
export type ProfileValues = z.infer<typeof profileSchema>;
export type ChangePasswordValues = z.infer<typeof changePasswordSchema>;
export type ForgotPasswordValues = z.infer<typeof forgotPasswordSchema>;
export type ResetPasswordValues = z.infer<typeof resetPasswordSchema>;
export type ConfirmPasswordValues = z.infer<typeof confirmPasswordSchema>;
