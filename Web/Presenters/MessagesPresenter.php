<?php declare(strict_types=1);
namespace Web\Presenters;
use Web\Entities\Localization;
use Web\Models\User;
use Web\Models\Sessions;
use Utilities\Strings;
use Web\Models\Messages;
use Latte;
#[\AllowDynamicProperties]
final class MessagesPresenter
{
    public function __construct()
    {
        $this->latte = new Latte\Engine();
        $this->latte->setTempDirectory($_SERVER['DOCUMENT_ROOT']."/temp");
    }

    public function load(array $params = []): void
    {
        $params += ["language" => $GLOBALS['language']];
        if(isset($_COOKIE['zhabbler_session'])){
            $session = (new Sessions())->get_session($_COOKIE['zhabbler_session']);
            $user = (new User())->get_user_by_token($session->sessionToken);
            $conversations = (new Messages())->get_conversations($user->token);
            if(isset($_GET['peer']) && (new User())->check_user_existence($_GET['peer']))
                $params += ["open_msgs" => $_GET['peer']];
            $params += ["user" => $user, "conversations" => $conversations];
            $this->latte->render($_SERVER['DOCUMENT_ROOT']."/Web/views/messages.latte", $params);
        }else{
            header("Location: /login?returnTo=".$_SERVER['REQUEST_URI']);
            die;
        }
    }
}
if(isset($params)){
    (new MessagesPresenter())->load($params);
}else{
    (new MessagesPresenter())->load();
}