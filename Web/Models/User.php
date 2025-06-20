<?php declare(strict_types=1);
namespace Web\Models;
use Utilities\Database;
use Utilities\Strings;
use Utilities\Emails;
use Web\Entities\Localization;
use Web\Models\Personalization;
use Web\Models\Sessions;
use Utilities\Files;
use Nette;
#[\AllowDynamicProperties]
class User
{
    public function __construct()
    {
        $this->locale = (new Localization())->get_language((isset($_COOKIE['zhabbler_language']) ? $_COOKIE['zhabbler_language'] : $GLOBALS['config']['application']['default_language']));
    }

    public function change_profile_image(string $token, ?array $file): void
    {
        header('Content-Type: application/json');
        $file = (new Files())->upload_image($token, $file, false);
        if($file['error'] == null){
            $filename = "/uploads/zhabbler_avatar_".(new Strings())->random_string(128).".jpeg";
            (new Files())->thumbnail_avatar_crop($_SERVER['DOCUMENT_ROOT']."/Web/public/".$file['url'], $_SERVER['DOCUMENT_ROOT']."/Web/public$filename");
            $user = $this->get_user_by_token($token);
            if($user->activated == 1){
                if($file['error'] == null && !empty($file['url'])){
                    $GLOBALS['db']->query("UPDATE users SET profileImage = ? WHERE token = ?", $filename, $token);
                    if(str_starts_with($user->profileImage, "/uploads/")){
                        unlink("{$_SERVER['DOCUMENT_ROOT']}/Web/public{$user->profileImage}");
                    }
                }
            }
        }
        die(json_encode((!isset($filename) ? $file : ["url" => $filename])));
    }

    public function ban_user(string $nickname, string $token, string $reason): void
    {
        $user = $this->get_user_by_token($token);
        $reason = (new Strings())->convert($reason);
        $who = $GLOBALS['db']->fetch("SELECT * FROM users WHERE nickname = ?", $nickname);
        if($user->admin == 1 && $who->userID != $user->userID){
            if($who->reason == ''){
                $GLOBALS['db']->query("UPDATE users SET reason = ? WHERE token = ?", $reason, $who->token);
            }else{
                $GLOBALS['db']->query("UPDATE users SET reason = '' WHERE token = ?", $who->token);
            }
        }
    }
    
    public function check_banned_user(string $nickname): bool
    {
        return ($GLOBALS['db']->fetch("SELECT * FROM users WHERE nickname = ?", $nickname)->reason == '' ? false : true);
    }

    public function check_banned_user_by_id(int $id): bool
    {
        return ($GLOBALS['db']->fetch("SELECT * FROM users WHERE userID = ?", $id)->reason == '' ? false : true);
    }

    public function search_users(string $query, int $lastID = 0): void
    {
        header('Content-Type: application/json');
        $query = (new Strings())->convert($query);
        $result = [];
        if(!(new Strings())->is_empty($query)){
            if($lastID == 0){
                $searched = $GLOBALS['db']->fetchAll("SELECT * FROM users WHERE nickname LIKE ? AND reason = '' AND activated = 1 ORDER BY userID DESC LIMIT 15", "%$query%");
            }else{
                $searched = $GLOBALS['db']->fetchAll("SELECT * FROM users WHERE nickname LIKE ? AND userID < ? AND reason = '' AND activated = 1 ORDER BY userID DESC LIMIT 15", "%$query%", $lastID);
            }
            foreach($searched as $search){
                $result[] = ["userID" => $search->userID, "profileImage" => $search->profileImage, "name" => $search->name, "nickname" => $search->nickname];
            }
        }
        die(json_encode($result));
    }

    public function get_query_count(string $query){
        return $GLOBALS['db']->query("SELECT * FROM users WHERE nickname LIKE ? AND reason = '' AND activated = 1", "%$query%")->getRowCount();
    }

    public function change_profile_cover(string $token, ?array $file): void
    {
        header('Content-Type: application/json');
        $file = (new Files())->upload_image($token, $file, false);
        $user = $this->get_user_by_token($token);
        if($user->activated == 1){
            if($file['error'] == NULL && !empty($file['url'])){
                $GLOBALS['db']->query("UPDATE users SET profileCover = ? WHERE token = ?", $file['url'], $token);
                if(!empty($user->profileCover))
                    unlink("{$_SERVER['DOCUMENT_ROOT']}/Web/public{$user->profileCover}");
            }
        }
        die(json_encode($file));
    }

    public function check_user_existence(string $nickname): bool
    {
        return ($GLOBALS['db']->query("SELECT * FROM users WHERE nickname = ? AND reason = ''", $nickname)->getRowCount() > 0 ? true : false);
    }

    public function check_user_existence_by_token(string $token): bool
    {
        return ($GLOBALS['db']->query("SELECT * FROM users WHERE token = ? AND reason = ''", $token)->getRowCount() > 0 ? true : false);
    }

    public function report(string $token, string $to): void
    {
        $user = $this->get_user_by_token($token);
        $who = $this->get_user_by_nickname($to);
        if($user->activated == 1){
            if($user->userID != $who->userID && $GLOBALS['db']->query("SELECT * FROM reports WHERE reportBy = ? AND reportTo = ?", $user->userID, $who->userID)->getRowCount() == 0){
                $GLOBALS['db']->query("INSERT INTO reports", [
                    "reportBy" => $user->userID,
                    "reportTo" => $who->userID
                ]);
            }
        }
    }
    
    public function password_reset(string $email): void
    {
        header('Content-Type: application/json');
        $result = ["error" => null];
        if(!empty($GLOBALS['config']['smtp']['host']) && !empty($GLOBALS['config']['smtp']['username']) && !empty($GLOBALS['config']['smtp']['email']) && !empty($GLOBALS['config']['smtp']['password'])){
            if(!(new Strings())->is_empty($email)){
                if($GLOBALS['db']->query("SELECT * FROM users WHERE email = ? AND activated = 1", $email)->getRowCount() > 0){
                    $user = $GLOBALS['db']->fetch("SELECT * FROM users WHERE email = ?", $email);
                    (new Emails())->createEmail(1, $user->userID);
                }
                $result = ["warning" => $this->locale["password_reset_email_sent"]];
            }else{
                $result = ["error" => $this->locale["some_fields_are_empty"]];
            }
        }else{
            $result = ["error" => "SMTP is not set. Check your configuration file."];
        }
        die(json_encode($result));
    }

    public function password_reset_change(string $code, string $password, string $repassword): void
    {
        header('Content-Type: application/json');
        $result = ["error" => null];
        if((new Emails())->checkEmailExistence(1, $code)){
            $email = (new Emails())->getEmail(1, $code);
            if($email->activated == 1){
                if($password == $repassword){
                    if(!(new Strings())->is_empty($password)){
                        if(strlen($password) < 8){
                            $result = ["error" => $this->locale["error_small_password"]];
                        }else{
                            (new Sessions())->removeSessions($email->token);
                            $password_hashed = password_hash($password, PASSWORD_DEFAULT);
                            $GLOBALS['db']->query("DELETE FROM emails WHERE emailType = ? AND emailFor = ?", 1, $email->userID);
                            $GLOBALS['db']->query("UPDATE users SET password = ? WHERE token = ?", $password_hashed, $email->token);
                        }
                    }else{
                        $result = ["error" => $this->locale["some_fields_are_empty"]];
                    }
                }else{
                    $result = ["error" => $this->locale["passwords_doesnt_match"]];
                }
            }else{
                $result = ["error" => "Account is not activated."];
            }
        }else{
            $result = ["error" => $this->locale["email_code_error"]];
        }
        die(json_encode($result));
    }

    public function change_confidential_settings(string $token, int $liked, int $following, int $questions, int $write_msgs): void
    {
        $liked = ($liked == 1 ? 1 : 0);
        $following = ($following == 1 ? 1 : 0);
        $questions = ($questions == 1 ? 1 : 0);
        $write_msgs = ($write_msgs <= 2 ? $write_msgs : 0);
        $GLOBALS['db']->query("UPDATE users SET hideLiked = ?, hideFollowing = ?, askQuestions = ?, whoCanWriteMsgs = ? WHERE token = ?", $liked, $following, $questions, $write_msgs, $token);
    }

    public function delete_account(string $password): void
    {
        header('Content-Type: application/json');
        $session = (new Sessions())->get_session($_COOKIE['zhabbler_session']);
        $user = $this->get_user_by_token($session->sessionToken);
        $result = ["error" => NULL];
        if(password_verify($password, $user->password)){
            (new Sessions())->removeSessions($session->sessionToken);
            $GLOBALS['db']->query("DELETE FROM users WHERE userID = ?", $user->userID);
            $GLOBALS['db']->query("DELETE FROM comments WHERE commentBy = ?", $user->userID);
            $GLOBALS['db']->query("DELETE FROM zhabs WHERE zhabBy = ?", $user->userID);
            $GLOBALS['db']->query("DELETE FROM notifications WHERE notificationTo = ? OR notificationBy = ?", $user->userID, $user->userID);
            $GLOBALS['db']->query("DELETE FROM conversations WHERE conversationBy = ? OR conversationTo = ?", $user->userID, $user->userID);
            $GLOBALS['db']->query("DELETE FROM messages WHERE messageBy = ? OR messageTo = ?", $user->userID, $user->userID);
            $GLOBALS['db']->query("DELETE FROM reports WHERE reportBy = ? OR reportTo = ?", $user->userID, $user->userID);
            $GLOBALS['db']->query("DELETE FROM likes WHERE likeBy = ?", $user->userID);
            $GLOBALS['db']->query("DELETE FROM inbox WHERE inboxTo = ? OR inboxBy = ?", $user->userID, $user->userID);
            $GLOBALS['db']->query("DELETE FROM follows WHERE followTo = ? OR followBy = ?", $user->userID, $user->userID);
            $GLOBALS['db']->query("DELETE FROM emails WHERE emailFor = ?", $user->userID);
        }else{
            $result = ["error" => $this->locale['incorrect_password']];
        }
        die(json_encode($result));
    }

    public function check_user_existence_by_id(int $id): bool
    {
        return ($GLOBALS['db']->query("SELECT * FROM users WHERE userID = ? AND reason = ''", $id)->getRowCount() > 0 ? true : false);
    }

    public function update_user_info(string $token, string $name, string $nickname, string $biography, string $accent, string $background): void
    {
        header('Content-Type: application/json');
        $name = (new Strings())->convert($name);
        $nickname = (new Strings())->convert($nickname);
        $bio = (!(new Strings())->is_empty($biography) ? $biography : "");
        $user = $this->get_user_by_token($token);
        $result = ["error" => NULL];
        if($user->activated == 1){
            if(!(new Strings())->is_empty($name) && !(new Strings())->is_empty($nickname)){
                if(strlen($name) > 48){
                    $result = ["error" => $this->locale['error_big_name']];
                }else if(strlen($nickname) < 3 || strlen($nickname) > 20){
                    $result = ["error" => $this->locale['error_big_nickname']];
                }else if(!preg_match("/^[a-zA-Z0-9]{3,}$/", $nickname)){
                    $result = ["error" => $this->locale['error_nickname_symbols']];
                }else if($nickname != $user->nickname && $GLOBALS['db']->query("SELECT * FROM users WHERE nickname = ?", $nickname)->getRowCount() > 0){
                    $result = ["error" => $this->locale['error_nickname_is_used']];
                }else if(!ctype_xdigit(str_replace('#', '', $accent)) || !ctype_xdigit(str_replace('#', '', $background))){
                    $result = ["error" => "Error with hex colors"];
                }else{
                    $GLOBALS['db']->query("UPDATE users SET name = ?, nickname = ?, biography = ?, accentColor = ?, backgroundColor = ? WHERE token = ?", $name, $nickname, $bio, $accent, $background, $token);
                }
            }else{
                $result = ["error" => $this->locale["some_fields_are_empty"]];
            }
        }
        die(json_encode($result));
    }

    public function get_user_by_token(string $token, bool $showEvenBanned = false): Nette\Database\Row
    {
        if(!$showEvenBanned){
            return $GLOBALS['db']->fetch("SELECT * FROM users WHERE token = ? AND reason = ''", $token);
        }else{
            return $GLOBALS['db']->fetch("SELECT * FROM users WHERE token = ?", $token);
        }
    }

    public function get_user_by_id(int $id, bool $showEvenBanned = false): Nette\Database\Row
    {
        if(!$showEvenBanned){
            return $GLOBALS['db']->fetch("SELECT * FROM users WHERE userID = ? AND reason = ''", $id);
        }else{
            return $GLOBALS['db']->fetch("SELECT * FROM users WHERE userID = ?", $id);
        }
    }

    public function get_user_by_nickname(string $nickname, bool $showEvenBanned = false): Nette\Database\Row
    {
        if(!$showEvenBanned){
            return $GLOBALS['db']->fetch("SELECT * FROM users WHERE nickname = ? AND reason = ''", $nickname);
        }else{
            return $GLOBALS['db']->fetch("SELECT * FROM users WHERE nickname = ?", $nickname);
        }
    }

    public function get_user_by_id_json(int $id): void
    {
        header('Content-Type: application/json');
        $user = $this->get_user_by_id($id);
        $result = ["profileImage" => $user->profileImage, "profileCover" => $user->profileCover, "nickname" => $user->nickname, "name" => $user->name, "biography" => $user->biography];
        die(json_encode($result));
    }

    public function get_user_by_token_json(string $token): void
    {
        header('Content-Type: application/json');
        $user = $this->get_user_by_token($token);
        $result = ["profileImage" => $user->profileImage, "profileCover" => $user->profileCover, "nickname" => $user->nickname, "name" => $user->name, "biography" => $user->biography];
        die(json_encode($result));
    }

    public function get_user_by_nickname_json(string $nickname): void
    {
        header('Content-Type: application/json');
        $user = $this->get_user_by_nickname($nickname);
        $result = ["profileImage" => $user->profileImage, "profileCover" => $user->profileCover, "nickname" => $user->nickname, "name" => $user->name, "biography" => $user->biography];
        die(json_encode($result));
    }

    public function parse_activitypub_user(string $user): array
    {
        $split = explode('@', $user, 2);
        if (count($split) != 2)
        {
            http_response_code(400);
            die();
        }

        return [
            'user' => $split[0],
            'host' => $split[1],
        ];
    }

    public function form_webfinger(string $resource): void
    {
        if (substr($resource, 0, 5) != 'acct:')
        {
            http_response_code(400);
            die();
        }

        $parsed = $this->parse_activitypub_user(substr($resource, 5));

        if ($parsed['host'] == $GLOBALS['config']['activitypub']['host'])
        {
            header('Content-Type: application/jrd+json; charset=utf-8');

            $user = $this->get_user_by_nickname($parsed['user']);
            $self = BASE_URL . 'profile/' . $user->nickname;
            $result = [
                'subject' => $resource,
                'aliases' => [ $self, ],
                'links' => [
                    [
                        'rel' => 'http://webfinger.net/rel/profile-page',
                        'type' => 'text/html',
                        'href' => $self,
                    ],
                    [
                        'rel' => 'self',
                        'type' => 'application/activity+json',
                        'href' => $self,
                    ],
                    [
                        'rel' => 'http://ostatus.org/schema/1.0/subscribe',
                        'template' => BASE_URL . 'api/authorize_interaction?uri={uri}',
                    ],
                    [
                        'rel' => 'http://webfinger.net/rel/avatar',
                        'type' => 'image/png',
                        'href' => BASE_URL . $user->profileImage,
                    ]
                ]
            ];

            die(json_encode($result));
        }

        http_response_code(404);
    }

    public function random_profiles(): array
    {
        return $GLOBALS['db']->fetchAll("SELECT * FROM users WHERE activated = 1 AND reason = '' AND rateLimitCounter > 0 ORDER BY rand() LIMIT 4");
    }

    public function recommended_profiles(string $token): array
    {
        $user = $this->get_user_by_token($token);
        $result = [];
        foreach($this->random_profiles() as $profile){
            if($user->userID != $profile->userID && !(new Follow())->check_follow_existence($user->token, $profile->userID)){
                $result[] = $profile;
            }
        }
        return $result;
    }

    public function change_email(string $email, string $password, string $token): void
    {
        header('Content-Type: application/json');
        $result = ["error" => NULL];
        $user = $this->get_user_by_token($token);
        if($user->activated == 1){
            if(password_verify($password, $user->password)){
                if($user->email != $email){
                    if($GLOBALS['db']->query("SELECT * FROM users WHERE email = ?", $email)->getRowCount() > 0){
                        $result = ["error" => $this->locale['error_email_is_used']];
                    }else{
                        if(!(new Strings())->is_empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)){
                            $GLOBALS["db"]->query("UPDATE users SET email = ? WHERE token = ?", $email, $token);
                        }else{
                            $result = ["error" => $this->locale["failed_change_email"]];
                        }
                    }
                }
            }else{
                $result = ["error" => $this->locale["incorrect_password"]];
            }
        }
        die(json_encode($result));
    }

    public function change_password(string $password, string $new_password, string $token): void
    {
        header('Content-Type: application/json');
        $user = $this->get_user_by_token($token);
        $result = ["error" => NULL];
        if($user->activated == 1){
            if(password_verify($password, $user->password)){
                if(strlen($new_password) < 8){
                    $result = ["error" => $this->locale['error_small_password']];
                }else{
                    (new Sessions())->removeSessions($token);
                    $password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $GLOBALS['db']->query("UPDATE users SET password = ? WHERE token = ?", $password_hashed, $token);
                }
            }else{
                $result = ["error" => $this->locale["incorrect_password"]];
            }
        }
        die(json_encode($result));
    }

    public function login(string $email, string $password, bool $json_answer = false): void
    {
        header('Content-Type: application/json');
        $result = ["error" => NULL];
        if(!(new Strings())->is_empty($email) && !(new Strings())->is_empty($password)){
            if($GLOBALS['db']->query("SELECT * FROM users WHERE email = ?", $email)->getRowCount() > 0){
                $tempUser = $GLOBALS['db']->fetch("SELECT * FROM users WHERE email = ?", $email);
                if(password_verify($password, $tempUser->password)){
                    if($tempUser->activated != 1 && $json_answer){
                        $result = ["warning" => $this->locale['need_to_verify_email']];
                    }else if(!empty($tempUser->reason)){
                        $result = ["warning" => $this->locale['login_error_banned_user'].$tempUser->reason];
                    }else{
                        $session = (new Sessions())->create($tempUser->token);
                        if($session != "ERROR"){
                            (new Notifications())->addNotify(4, $tempUser->token, $tempUser->userID, "/settings/account");
                            if($json_answer){
                                $result = ["error" => NULL, "session" => $session];
                            }else{
                                setcookie("zhabbler_session", $session, time()+7000000, "/");
                            }
                        }else{
                            $result = ["error" => "Error with session"];
                        }
                    }
                }else{
                    $result = ["error" => $this->locale['incorrect_password']];
                }
            }else{
                $result = ["error" => $this->locale['user_does_not_exists']];
            }
        }else{
            $result = ["error" => $this->locale['some_fields_are_empty']];
        }
        die(json_encode($result));
    }

    public function register(string $name, string $nickname, string $email, string $password, bool $ignore_config = false): void
    {
        header('Content-Type: application/json');
        if($ignore_config || $GLOBALS['config']['application']['registration_opened'] == 1){
            $result = ["error" => NULL];
            $name = (new Strings())->convert($name);
            $nickname = (new Strings())->convert($nickname);
            if(!(new Strings())->is_empty($name) && !(new Strings())->is_empty($nickname) && !(new Strings())->is_empty($email) && !(new Strings())->is_empty($password)){
                if(strlen($name) > 48){
                    $result = ["error" => $this->locale['error_big_name']];
                }else if(strlen($nickname) < 3 || strlen($nickname) > 20){
                    $result = ["error" => $this->locale['error_big_nickname']];
                }else if(strlen($password) < 8){
                    $result = ["error" => $this->locale['error_small_password']];
                }else if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                    $result = ["error" => $this->locale['error_with_email']];
                }else if($GLOBALS['db']->query("SELECT * FROM users WHERE nickname = ?", $nickname)->getRowCount() > 0){
                    $result = ["error" => $this->locale['error_nickname_is_used']];
                }else if($GLOBALS['db']->query("SELECT * FROM users WHERE email = ?", $email)->getRowCount() > 0){
                    $result = ["error" => $this->locale['error_email_is_used']];
                }else if(!preg_match("/^[a-zA-Z0-9]{3,}$/", $nickname)){
                    $result = ["error" => $this->locale['error_nickname_symbols']];
                }else if($nickname == 'anonymous'){
                    $result = ['error'=> "Forbidden username"];
                }else{
                    $password = password_hash($password, PASSWORD_DEFAULT);
                    $token = (new Strings())->random_string(255);
                    $GLOBALS['db']->query("INSERT INTO users", [
                        "name" => $name,
                        "nickname" => $nickname,
                        "email" => $email,
                        "password" => $password,
                        "profileImage" => "/static/images/avatars/".rand(1,5).".png",
                        "token" => $token,
                        "joined" => date("Y-m-d"),
                        "activated" => ($GLOBALS['config']['application']['email_verification'] == 1 ? 0 : 1)
                    ]);
                    (new Personalization())->add_personalization_config($token);
                    $user = $GLOBALS['db']->fetch("SELECT * FROM users WHERE token = ?", $token);
                    if($user->activated == 1){
                        if(!$ignore_config){
                            $session = (new Sessions())->create($token);
                            if($session != "ERROR"){
                                setcookie("zhabbler_session", $session, time()+7000000, "/");
                            }else{
                                $result = ["error" => "Error with session"];
                            }
                        }
                    }else{
                        $result = ["warning" => $this->locale['need_to_verify_email']];
                        (new Emails())->createEmail(0, $user->userID);
                    }
                }
            }else{
                $result = ["error" => $this->locale['some_fields_are_empty']];
            }
        }else{
            $result = ["error" => $this->locale["register_closed_info"]];
        }
        if($result['error'] == NULL && $ignore_config){
            header("Location: /admin/users/".$nickname);
        }else{
            die(json_encode($result));
        }
    }
}
