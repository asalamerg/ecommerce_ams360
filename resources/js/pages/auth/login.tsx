import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { login, register } from '@/routes';
import staffLoginRoutes from '@/routes/admin/login';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    staffLogin?: boolean;
};

export default function Login({
    status,
    canResetPassword,
    staffLogin = false,
}: Props) {
    const t = useTranslations();
    const title = t(staffLogin ? 'auth.staff_login_title' : 'auth.login_title');

    setLayoutProps({
        title,
        description: t(
            staffLogin
                ? 'auth.staff_login_description'
                : 'auth.login_description',
        ),
    });

    return (
        <>
            <Head title={title} />

            {status && (
                <div
                    role="status"
                    className="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                >
                    {status}
                </div>
            )}

            {staffLogin && (
                <PasskeyVerify
                    label={t('auth.passkey_sign_in')}
                    loadingLabel={t('auth.passkey_authenticating')}
                    separator={t('auth.passkey_or_email')}
                />
            )}

            <Form
                {...(staffLogin ? staffLoginRoutes.store.form() : store.form())}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label htmlFor="email">{t('auth.email')}</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    dir="ltr"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder={t('auth.email_placeholder')}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {t('auth.password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ms-auto text-sm"
                                            tabIndex={5}
                                        >
                                            {t('auth.forgot_password')}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={t('auth.password_placeholder')}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    {t('auth.remember_me')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 h-11 w-full bg-brand font-bold text-on-brand transition hover:bg-brand-hover"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.login')}
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {staffLogin ? (
                                <TextLink href={login()} tabIndex={6}>
                                    {t('auth.customer_login_link')}
                                </TextLink>
                            ) : (
                                <>
                                    {t('auth.no_account')}{' '}
                                    <TextLink
                                        href={register()}
                                        data-test="register-link"
                                        tabIndex={6}
                                    >
                                        {t('auth.sign_up')}
                                    </TextLink>
                                </>
                            )}
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
