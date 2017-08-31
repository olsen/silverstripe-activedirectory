<?php

namespace SilverStripe\ActiveDirectory\Authenticators;

use SilverStripe\ActiveDirectory\Services\LDAPService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Authenticator;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\LogoutHandler;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;

/**
 * Class LDAPAuthenticator
 *
 * Authenticate a user against LDAP, without the single sign-on component.
 *
 * See SAMLAuthenticator for further information.
 *
 * @package activedirectory
 */
class LDAPAuthenticator extends MemberAuthenticator
{
    /**
     * @var string
     */
    private $name = 'LDAP';

    /**
     * Set to 'yes' to indicate if this module should look up usernames in LDAP by matching the email addresses.
     *
     * CAVEAT #1: only set to 'yes' for systems that enforce email uniqueness.
     * Otherwise only the first LDAP user with matching email will be accessible.
     *
     * CAVEAT #2: this is untested for systems that use LDAP with principal style usernames (i.e. foo@bar.com).
     * The system will misunderstand emails for usernames with uncertain outcome.
     *
     * @var string 'no' or 'yes'
     */
    private static $allow_email_login = 'no';

    /**
     * Set to 'yes' to fallback login attempts to {@link $fallback_authenticator}.
     * This will occur if LDAP fails to authenticate the user.
     *
     * @var string 'no' or 'yes'
     */
    private static $fallback_authenticator = 'no';

    /**
     * The class of {@link Authenticator} to use as the fallback authenticator.
     *
     * @var string
     */
    private static $fallback_authenticator_class = MemberAuthenticator::class;

    /**
     * @return string
     */
    public static function get_name()
    {
        return Config::inst()->get(self::class, 'name');
    }

    /**
     * @param Controller $controller
     * @return LDAPLoginForm
     */
    public static function get_login_form(Controller $controller)
    {
        return new LDAPLoginForm($controller, 'LoginForm');
    }

    /**
     * Performs the login, but will also create and sync the Member record on-the-fly, if not found.
     *
     * @param array $data
     * @param HTTPRequest $request
     * @param ValidationResult|null $result
     * @return null|Member
     * @internal param Form $form
     */
    public function authenticate(array $data, HTTPRequest $request, ValidationResult &$result = null)
    {
        /** @var LDAPService $service */
        $service = Injector::inst()->get(LDAPService::class);
        $login = trim($data['Login']);
        if (Email::is_valid_address($login)) {
            if (Config::inst()->get(self::class, 'allow_email_login') != 'yes') {
                $result->addError(
                    _t(
                        'LDAPAuthenticator.PLEASEUSEUSERNAME',
                        'Please enter your username instead of your email to log in.'
                    )
                );
                return null;
            }

            $username = $service->getUsernameByEmail($login);

            // No user found with this email.
            if (!$username) {
                if (Config::inst()->get(self::class, 'fallback_authenticator') === 'yes') {
                    if ($fallbackMember = $this->fallbackAuthenticate($data, $request)) {
                        {
                            return $fallbackMember;
                        }
                    }
                }

                $result->addError(_t('LDAPAuthenticator.INVALIDCREDENTIALS', 'Invalid credentials'));
                return null;
            }
        } else {
            $username = $login;
        }

        $serviceAuthenticationResult = $service->authenticate($username, $data['Password']);
        $success = $serviceAuthenticationResult['success'] === true;
        if (!$success) {
            if (Config::inst()->get(self::class, 'fallback_authenticator') === 'yes') {
                $fallbackMember = $this->fallbackAuthenticate($data, $request);
                if ($fallbackMember) {
                    return $fallbackMember;
                }
            }

            $result->addError($serviceAuthenticationResult['message']);

            return null;
        }

        $data = $service->getUserByUsername($serviceAuthenticationResult['identity']);
        if (!$data) {
            $result->addError(
                _t(
                    'LDAPAuthenticator.PROBLEMFINDINGDATA',
                    'There was a problem retrieving your user data'
                )
            );
            return null;
        }

        // LDAPMemberExtension::memberLoggedIn() will update any other AD attributes mapped to Member fields
        $member = Member::get()->filter('GUID', $data['objectguid'])->limit(1)->first();
        if (!($member && $member->exists())) {
            $member = new Member();
            $member->GUID = $data['objectguid'];
        }

        // Update the users from LDAP so we are sure that the email is correct.
        // This will also write the Member record.
        $service->updateMemberFromLDAP($member);

        $request->getSession()->clear('BackURL');

        return $member;
    }

    /**
     * Try to authenticate using the fallback authenticator.
     *
     * @param array $data
     * @param HTTPRequest $request
     * @return null|Member
     */
    protected function fallbackAuthenticate($data, HTTPRequest $request)
    {
        $authenticatorClass = Config::inst()->get(self::class, 'fallback_authenticator_class');
        if ($authenticator = Injector::inst()->get($authenticatorClass)) {
            $result = call_user_func(
                [
                    $authenticator,
                    'authenticate'
                ],
                $data,
                $request
            );
            return $result;
        }
    }

    public function getLoginHandler($link)
    {
        return LDAPLoginHandler::create($link, $this);
    }

    public function supportedServices()
    {
        $result = (bool)LDAPService::config()->get('allow_password_change') ?
            Authenticator::LOGIN | Authenticator::LOGOUT | Authenticator::CHANGE_PASSWORD :
            Authenticator::LOGIN | Authenticator::LOGOUT;
        return $result;
    }
}
