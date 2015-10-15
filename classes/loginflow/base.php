<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package auth_oidc
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace auth_oidc\loginflow;

class base {
    /** @var object Plugin config. */
    public $config;

    /** @var \auth_oidc\httpclientinterface An HTTP client to use. */
    protected $httpclient;

    public function __construct() {
        $default = [
            'opname' => get_string('pluginname', 'auth_oidc')
        ];
        $storedconfig = (array)get_config('auth_oidc');
        $forcedconfig = [
            'field_updatelocal_idnumber' => 'oncreate',
            'field_lock_idnumber' => 'locked',
            'field_updatelocal_lang' => 'oncreate',
            'field_lock_lang' => 'locked',
            'field_updatelocal_firstname' => 'onlogin',
            'field_lock_firstname' => 'unlocked',
            'field_updatelocal_lastname' => 'onlogin',
            'field_lock_lastname' => 'unlocked',
            'field_updatelocal_email' => 'onlogin',
            'field_lock_email' => 'unlocked',
        ];

        $this->config = (object)array_merge($default, $storedconfig, $forcedconfig);
    }

    /**
     * Returns a list of potential IdPs that this authentication plugin supports. Used to provide links on the login page.
     *
     * @param string $wantsurl The relative url fragment the user wants to get to.
     * @return array Array of idps.
     */
    public function loginpage_idp_list($wantsurl) {
        return [];
    }

    /**
     * This is the primary method that is used by the authenticate_user_login() function in moodlelib.php.
     *
     * @param string $username The username (with system magic quotes)
     * @param string $password The password (with system magic quotes)
     * @return bool Authentication success or failure.
     */
    public function user_login($username, $password = null) {
        return false;
    }

    /**
     * Provides a hook into the login page.
     *
     * @param object &$frm Form object.
     * @param object &$user User object.
     */
    public function loginpage_hook(&$frm, &$user) {
        return true;
    }

    /**
     * Read user information from external database and returns it as array().
     *
     * @param string $username username
     * @return mixed array with no magic quotes or false on error
     */
    public function get_userinfo($username) {
        global $DB;

        $tokenrec = $DB->get_record('auth_oidc_token', ['username' => $username]);
        if (empty($tokenrec)) {
            return false;
        }

        $idtoken = \auth_oidc\jwt::instance_from_encoded($tokenrec->idtoken);

        $userinfo = [
            'lang' => 'en',
            'idnumber' => $username,
        ];

        $cfg_firstname = empty($this->config->firstname) ? 'given_name' : $this->config->firstname;
        $firstname = $idtoken->claim($cfg_firstname);
        if (!empty($firstname)) {
            $userinfo['firstname'] = $firstname;
        }

        $cfg_lastname = empty($this->config->lastname) ? 'family_name' : $this->config->lastname;
        $lastname = $idtoken->claim($cfg_lastname);
        if (!empty($lastname)) {
            $userinfo['lastname'] = $lastname;
        }

        $cfg_email = empty($this->config->email) ? 'email' : $this->config->email;
        $email = $idtoken->claim($cfg_email);
        if (!empty($email)) {
            $userinfo['email'] = $email;
        }

        return $userinfo;
    }

    /**
     * Set an HTTP client to use.
     *
     * @param auth_oidchttpclientinterface $httpclient [description]
     */
    public function set_httpclient(\auth_oidc\httpclientinterface $httpclient) {
        $this->httpclient = $httpclient;
    }

    /**
     * Handle OIDC disconnection from Moodle account.
     *
     * @param bool $justremovetokens If true, just remove the stored OIDC tokens for the user, otherwise revert login methods.
     */
    public function disconnect($justremovetokens = false, \moodle_url $redirect = null) {
        if ($redirect === null) {
            $redirect = new \moodle_url('/auth/oidc/ucp.php');
        }
        if ($justremovetokens === true) {
            global $USER, $DB, $CFG;
            // Delete token data.
            $DB->delete_records('auth_oidc_token', ['username' => $USER->username]);
            $eventdata = ['objectid' => $USER->id, 'userid' => $USER->id];
            $event = \auth_oidc\event\user_disconnected::create($eventdata);
            $event->trigger();
            redirect($redirect);
        } else {
            global $OUTPUT, $PAGE, $USER, $DB, $CFG;
            require_once($CFG->dirroot.'/user/lib.php');
            $PAGE->set_url('/auth/oidc/ucp.php');
            $PAGE->set_context(\context_system::instance());
            $PAGE->set_pagelayout('standard');
            $USER->editing = false;

            $ucptitle = get_string('ucp_disconnect_title', 'auth_oidc', $this->config->opname);
            $PAGE->navbar->add($ucptitle, $PAGE->url);
            $PAGE->set_title($ucptitle);

            // Check if we have recorded the user's previous login method.
            $prevmethodrec = $DB->get_record('auth_oidc_prevlogin', ['userid' => $USER->id]);
            $prevauthmethod = (!empty($prevmethodrec) && is_enabled_auth($prevmethodrec->method) === true)
                    ? $prevmethodrec->method : null;
            // Manual is always available, we don't need it twice.
            if ($prevauthmethod === 'manual') {
                $prevauthmethod = null;
            }

            // We need either the user's previous method or the manual login plugin to be enabled for disconnection.
            if (empty($prevauthmethod) && is_enabled_auth('manual') !== true) {
                throw new \moodle_exception('errornodisconnectionauthmethod', 'auth_oidc');
            }

            // Check to see if the user has a username created by OIDC, or a self-created username.
            // OIDC-created usernames are usually very verbose, so we'll allow them to choose a sensible one.
            // Otherwise, keep their existing username.
            $oidctoken = $DB->get_record('auth_oidc_token', ['username' => $USER->username]);
            $ccun = (isset($oidctoken->oidcuniqid) && strtolower($oidctoken->oidcuniqid) === $USER->username) ? true : false;
            $customdata = [
                'canchooseusername' => $ccun,
                'prevmethod' => $prevauthmethod,
            ];

            $mform = new \auth_oidc\form\disconnect('?action=disconnectlogin', $customdata);

            if ($mform->is_cancelled()) {
                redirect($redirect);
            } else if ($fromform = $mform->get_data()) {

                $origusername = $USER->username;

                if (empty($fromform->newmethod) || ($fromform->newmethod !== $prevauthmethod && $fromform->newmethod !== 'manual')) {
                    throw new \moodle_exception('errorauthdisconnectinvalidmethod', 'auth_oidc');
                }

                $updateduser = new \stdClass;

                if ($fromform->newmethod === 'manual') {
                    if (empty($fromform->password)) {
                        throw new \moodle_exception('errorauthdisconnectemptypassword', 'auth_oidc');
                    }
                    if ($customdata['canchooseusername'] === true) {
                        if (empty($fromform->username)) {
                            throw new \moodle_exception('errorauthdisconnectemptyusername', 'auth_oidc');
                        }

                        if (strtolower($fromform->username) !== $USER->username) {
                            $newusername = strtolower($fromform->username);
                            $usercheck = ['username' => $newusername, 'mnethostid' => $CFG->mnet_localhost_id];
                            if ($DB->record_exists('user', $usercheck) === false) {
                                $updateduser->username = $newusername;
                            } else {
                                throw new \moodle_exception('errorauthdisconnectusernameexists', 'auth_oidc');
                            }
                        }
                    }
                    $updateduser->auth = 'manual';
                    $updateduser->password = $fromform->password;
                } else if ($fromform->newmethod === $prevauthmethod) {
                    $updateduser->auth = $prevauthmethod;
                    //  We can't use user_update_user as it will rehash the value.
                    if (!empty($prevmethodrec->password)) {
                        $manualuserupdate = new \stdClass;
                        $manualuserupdate->id = $USER->id;
                        $manualuserupdate->password = $prevmethodrec->password;
                        $DB->update_record('user', $manualuserupdate);
                    }
                }

                // Update user.
                $updateduser->id = $USER->id;
                user_update_user($updateduser);

                // Delete token data.
                $DB->delete_records('auth_oidc_token', ['username' => $origusername]);

                $eventdata = ['objectid' => $USER->id, 'userid' => $USER->id];
                $event = \auth_oidc\event\user_disconnected::create($eventdata);
                $event->trigger();

                $USER = $DB->get_record('user', ['id' => $USER->id]);
                redirect($redirect);
            }

            echo $OUTPUT->header();
            $mform->display();
            echo $OUTPUT->footer();
        }
    }

    /**
     * Handle requests to the redirect URL.
     *
     * @return mixed Determined by loginflow.
     */
    public function handleredirect() {

    }

    /**
     * Construct the OpenID Connect client.
     *
     * @return \auth_oidc\oidcclient The constructed client.
     */
    protected function get_oidcclient() {
        if (empty($this->httpclient) || !($this->httpclient instanceof \auth_oidc\httpclientinterface)) {
            $this->httpclient = new \auth_oidc\httpclient();
        }
        if (empty($this->config->clientid) || empty($this->config->clientsecret)) {
            throw new \moodle_exception('errorauthnocreds', 'auth_oidc');
        }
        if (empty($this->config->authendpoint) || empty($this->config->tokenendpoint)) {
            throw new \moodle_exception('errorauthnoendpoints', 'auth_oidc');
        }

        $clientid = (isset($this->config->clientid)) ? $this->config->clientid : null;
        $clientsecret = (isset($this->config->clientsecret)) ? $this->config->clientsecret : null;
        $redirecturi = new \moodle_url('/auth/oidc/');
        $resource = (isset($this->config->oidcresource)) ? $this->config->oidcresource : null;

        $client = new \auth_oidc\oidcclient($this->httpclient);
        $client->setcreds($clientid, $clientsecret, $redirecturi->out(), $resource);

        $client->setendpoints(['auth' => $this->config->authendpoint, 'token' => $this->config->tokenendpoint]);
        return $client;
    }

    /**
     * Process an idtoken, extract uniqid and construct jwt object.
     *
     * @param string $idtoken Encoded id token.
     * @param string $orignonce Original nonce to validate received nonce against.
     * @return array List of oidcuniqid and constructed idtoken jwt.
     */
    protected function process_idtoken($idtoken, $orignonce = '') {
        // Decode and verify idtoken.
        $idtoken = \auth_oidc\jwt::instance_from_encoded($idtoken);
        $sub = $idtoken->claim('sub');
        if (empty($sub)) {
            throw new \moodle_exception('errorauthinvalididtoken', 'auth_oidc');
        }
        $receivednonce = $idtoken->claim('nonce');
        if (!empty($orignonce) && (empty($receivednonce) || $receivednonce !== $orignonce)) {
            throw new \moodle_exception('errorauthinvalididtoken', 'auth_oidc');
        }

        // Use 'oid' if available (Azure-specific), or fall back to standard "sub" claim.
        $oidcuniqid = $idtoken->claim('oid');
        if (empty($oidcuniqid)) {
            $oidcuniqid = $idtoken->claim('sub');
        }
        return [$oidcuniqid, $idtoken];
    }


    /**
     * Create a token for a user, thus linking a Moodle user to an OpenID Connect user.
     *
     * @param string $oidcuniqid A unique identifier for the user.
     * @param array $username The username of the Moodle user to link to.
     * @param array $authparams Parameters receieved from the auth request.
     * @param array $tokenparams Parameters received from the token request.
     * @param \auth_oidc\jwt $idtoken A JWT object representing the received id_token.
     * @return \stdClass The created token database record.
     */
    protected function createtoken($oidcuniqid, $username, $authparams, $tokenparams, \auth_oidc\jwt $idtoken) {
        global $DB;

        // Determine remote username. Use 'upn' if available (Azure-specific), or fall back to standard 'sub'.
        $oidcusername = $idtoken->claim('upn');
        if (empty($oidcusername)) {
            $oidcusername = $idtoken->claim('sub');
        }

        // We should not fail here (idtoken was verified earlier to at least contain 'sub', but just in case...).
        if (empty($oidcusername)) {
            throw new \moodle_exception('errorauthinvalididtoken', 'auth_oidc');
        }

        $tokenrec = new \stdClass;
        $tokenrec->oidcuniqid = $oidcuniqid;
        $tokenrec->username = $username;
        $tokenrec->oidcusername = $oidcusername;
        $tokenrec->scope = $tokenparams['scope'];
        $tokenrec->resource = $tokenparams['resource'];
        $tokenrec->authcode = $authparams['code'];
        $tokenrec->token = $tokenparams['access_token'];
        $tokenrec->expiry = $tokenparams['expires_on'];
        $tokenrec->refreshtoken = $tokenparams['refresh_token'];
        $tokenrec->idtoken = $tokenparams['id_token'];
        $tokenrec->id = $DB->insert_record('auth_oidc_token', $tokenrec);
        return $tokenrec;
    }

    /**
     * Update a token with a new auth code and access token data.
     *
     * @param int $tokenid The database record ID of the token to update.
     * @param array $authparams Parameters receieved from the auth request.
     * @param array $tokenparams Parameters received from the token request.
     */
    protected function updatetoken($tokenid, $authparams, $tokenparams) {
        global $DB;
        $tokenrec = new \stdClass;
        $tokenrec->id = $tokenid;
        $tokenrec->authcode = $authparams['code'];
        $tokenrec->token = $tokenparams['access_token'];
        $tokenrec->expiry = $tokenparams['expires_on'];
        $tokenrec->refreshtoken = $tokenparams['refresh_token'];
        $tokenrec->idtoken = $tokenparams['id_token'];
        $DB->update_record('auth_oidc_token', $tokenrec);
    }
}