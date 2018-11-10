<?php
declare(ticks = 1);
function sig_handler($signo, $asd)
{
  die("Terminated");
}
pcntl_signal(SIGTERM, "sig_handler");
if (!file_exists('madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
}
require_once 'madeline.php';

class EventHandler extends \danog\MadelineProto\EventHandler
{
    private const botID = 123456; //bot manager
    private static $admins = [
        123456, // ID
        self::botID, // BOT
    ];
    private const logchannel = -100123456; //log channel
    private $ourid = null;
    private $init = false;

    private function pcall($method, $params, $noresponse = false)
    {
        try {
            return $this->API->method_call($method, $params, ['datacenter' => $this->API->datacenter->curdc, 'noResponse' => $noresponse]);
        } catch (\danog\MadelineProto\Exception $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => (string)$e->getMessage()]);
        } catch (\danog\MadelineProto\TL\Exception $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => (string)$e->getMessage()]);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            if (strpos($e->rpc, "FLOOD_WAIT_") !== false) {
                $this->messages->sendMessage(['peer' => self::logchannel, 'text' => "Flood wait $method ".$e->rpc]);
                sleep(str_replace("FLOOD_WAIT_", "", $e->rpc));
            } else {
                if (!in_array($e->rpc, ['USER_ALREADY_PARTICIPANT']))
                $this->messages->sendMessage(['peer' => self::logchannel, 'text' => (string)$e->getMessage()]);
            }
        }

        return false;
    }

    private function getId($peer)
    {
        if (isset($peer['chat_id'])) {
            return -$peer['chat_id'];
        }
        if (isset($peer['user_id'])) {
            return $peer['user_id'];
        }
        if (isset($peer['channel_id'])) {
            return $this->to_supergroup($peer['channel_id']);
        }
        return false;
    }

    private function getOurId()
    {
        if ($this->ourid === null) {
            $ourid = $this->get_self()['id'];
        }
        return $ourid;
    }

    public function __construct($MadelineProto)
    {
        parent::__construct($MadelineProto);
    }

    public function onUpdateNewChannelMessage($update)
    {
        $this->onUpdateNewMessage($update);
    }

    private function findMessageIdByArray($array)
    {
        array_walk_recursive($array, function ($k, $v) {
            if ($k === "id") {
                return $v;
            }
        });
    }

    public function onUpdateNewMessage($update)
    {
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }

        if (time() - $update['message']['date'] > 5) {
            return;
        }
        if (isset($update['message']['_']) && $update['message']['_'] === "messageService") {
            if (isset($update['message']['action']) &&
                ($update['message']['action']['_'] === "messageActionChatJoinedByLink" ||
                ($update['message']['action']['_'] === 'messageActionChatAddUser' && in_array($this->getOurId(), $update['message']['action']['users'])
            ))) {
                $this->sm(self::logchannel, "Joined ".$this->getId($update['message']['to_id']));
                $this->sm(self::botID, "/ubjoined ".$this->getId($update['message']['to_id']));
            }
        }
        if (!isset($update['message']['message'])) {
            return;
        }
        if (!isset($update['message']['from_id'])) {
            return;
        }
        $userID = $update['message']['from_id'];
        $chatID = $this->getId($update['message']['to_id']);
        if ($chatID === $this->getOurId()) {
            $chatID = $userID;
        }
        $text = $update['message']['message'];
        if (in_array($userID, self::$admins)) {
            $a = explode(" ", $text);
            if ($a[0] === "!join") {
                $res = $this->joinchat($a[1]);
                if (isset($res['updates'])) {
                    foreach ($res['updates'] as $up) {
                        if (isset($up['message']['_']) && $up['message']['_'] === "messageService") {
                            if (isset($up['message']['action']) &&
                                (($up['message']['action']['_'] === "messageActionChatJoinedByLink" && $up['message']['from_id'] == $this->getOurId()) ||
                                ($up['message']['action']['_'] === 'messageActionChatAddUser' && in_array($this->getOurId(), $up['message']['action']['users'])
                            ))) {
                                $this->sm(self::botID, "/ubjoined ".$this->getId($up['message']['to_id']));
                            }
                        }
                    }
                }
            } elseif ($a[0] === "!leave") {
                $this->leavechat(isset($a[1])?$a[1]:$chatID);
            } elseif ($a[0] === "!clearchat" && isset($a[1])) {
                $this->sm(self::logchannel, "Richiesta pulizia $a[1]");
                $rights = $this->getUserInChat($a[1], $this->getOurId());
                if (!(isset($rights['admin_rights']) && isset($rights['admin_rights']['delete_messages']) && $rights['admin_rights']['delete_messages'])) {
                    $this->sm($chatID, "/failpulizia $a[1]");
                    $this->sm(self::logchannel, "#failclear $a[1] not admin");
                    return;
                }
                $start = microtime(true);
                $this->clearchatk($a[1]);
                $t = microtime(true)-$start;
                echo "Messages deleted in $t s\n";
                $this->leavechat($a[1]);
                $this->sm($chatID, "/finepulizia $a[1]");
                $this->sm(self::logchannel, "#clearchat $a[1] $t secondi");
            } elseif ($a[0] === "!ping") {
                $this->sm($chatID, 'pong');
            } elseif ($a[0] === "!init" && !$this->init) {
                $this->sm($chatID, "Zi badrone io mi registro a group help");
                $this->sm("@registerbot", "/registercode");
            } elseif ($a[0] === "!forceinit") {
                $this->init = false;
                $this->get_pwr_chat("@registerbot");
                $this->sm("@registerbot", "/registercode");
            } elseif ($a[0] === "!setname" && isset($a[1])) {
                $this->updateName(substr($text, 9));
                $this->sm($chatID, "Nome cambiato");
            } elseif ($a[0] === "!setlastname") {
                $this->updateLastName(substr($text, 13));
                $this->sm($chatID, "Cognome cambiato");
            } elseif ($a[0] === "!setpropic") {
                $this->updatePropic($a[1]);
                $this->sm($chatID, "Propic cambiata");
            } elseif ($a[0] === "!setbio") {
                $this->updateBio(substr($text, 8));
                $this->sm($chatID, "Bio cambiata");
            } elseif ($a[0] === "!setpassword") {
                $asd = substr($text, 13);
                $asd = explode ("|", $asd);
                if (count($asd) === 2) {
                    $this->changePassword(trim($asd[0]), trim($asd[1]));
                } else {
                    $this->changePassword("", trim($asd[0]));
                }
            } elseif ($a[0] === "!delpassword") {
                $this->changePassword(substr($text, 13), "");
            } elseif ($a[0] === "!die") {
                //don't save chats in serialization
                $this->API->chats = [];
                $this->API->chats_full = [];
                //$this->sm($chatID, "😢");
                die();
            }
        }
        if ($userID === self::botID && $text === "userbot aggiunto") {
            $this->sm(self::logchannel, "Accettato");
            $this->init = true;
        }
    }

    public function getUserInChat($chatID, $userID)
    {
        $res = $this->pcall("channels.getParticipant", ['channel' => $chatID, 'user_id' => $userID]);
        return $res['participant'];
    }

    public function changePassword($oldpassword, $newpassword)
    {
        $getpassword = $this->account->getPassword();
        $current_salt = isset($getpassword['current_salt']) ? $getpassword['current_salt'] : "";
        $new_salt = empty($newpassword) ? "" : $getpassword['new_salt'].$this->random(8);
        $res = $this->pcall("account.updatePasswordSettings", [
            'current_password_settings' => new \danog\MadelineProto\TL\Types\Bytes(hash('sha256', $current_salt.$oldpassword.$current_salt, true)),
            'new_settings' => [
                '_' => 'account.passwordInputSettings',
                'new_salt' => new \danog\MadelineProto\TL\Types\Bytes($new_salt),
                'new_password_hash' => new \daong\MadelineProto\TL\Types\Bytes(hash('sha256', $new_salt.$newpassword.$new_salt, true)),
                'email' => 'your@email.com'
            ]
        ]);
        echo json_encode($res, 128).PHP_EOL;
    }

    public function updateName($name)
    {
        $this->pcall("account.updateProfile", ['first_name' => $name]);
    }

    public function updateLastName($name)
    {
        if (empty($name)) {
            $name = "";
        }
        $this->pcall("account.updateProfile", ['last_name' => $name]);
    }

    public function updateBio($name)
    {
        if (empty($name)) {
            $name = "";
        }
        $this->pcall("account.updateProfile", ['about' => $name]);
    }

    public function updatePropic($link)
    {
        if (empty($link)) {
            return false;
        }
        $pic = @file_get_contents($link);
        $pic = @imagecreatefromstring($pic);
        if (!$pic) {
            return false;
        }
        $filename = "pic".bin2hex(random_bytes(8))."jpg";
        $file = fopen($filename, "w");
        imagejpeg($pic, $file);
        fclose($file);
        $this->pcall("photos.uploadProfilePhoto", ['file' => $this->upload($filename)]);
        unlink($filename);
    }

    public function getLastMessageID($chat)
    {
        $msghistory = $this->pcall("messages.getHistory", ['peer' => $chat, 'offset_id' => 0, 'offset_date' => 0, 'add_offset' => 0, 'limit' => 1, 'max_id' => 2147483647, 'min_id' => 0, 'hash' => 0, ]);
        if (!$msghistory || !isset($msghistory['messages'])) {
            return false;
        }
        var_dump($msghistory);
        return $msghistory['messages'][0]['id'];
    }

    public function clearchatK($chat)
    {
        $last = $this->getLastMessageID($chat);
        $range = range($last,1,-1);
        $ranges = array_chunk($range, 100);
        foreach ($ranges as $range) {
            $this->deleteMessages($chat, $range);
        }
    }

    public function clearchat($chat)
    {
        try {
            $full_info = $this->get_full_info($chat);
            if (!isset($full_info['full']['hidden_prehistory']) || !$full_info['full']['hidden_prehistory']) {
                //batch delete
                $i = 0;
                while (true) {
                    echo "Batch delete $i\n";
                    $i++;
                    $msghistory = $this->pcall("messages.getHistory", ['peer' => $chat, 'offset_id' => 0, 'offset_date' => 0, 'add_offset' => 0, 'limit' => 0, 'max_id' => 2147483647, 'min_id' => -2147483648, 'hash' => 0, ]);
/*
                    if (!$msghistory || empty($msghistory) || empty($msghistory['messages'])) {
                        return;
                    }
*/
                    if (count($msghistory['messages']) === 1) {
                        $msg = $msghistory['messages'][0];
                        if ($msg['_'] === "messageService" && isset($msg['action']) && $msg['action']['_'] === "messageActionChannelMigrateFrom") {
                            return;
                        }
                    }

                    if (empty($msghistory['users'])) return;

                    foreach ($msghistory['users'] as $msgh) {
                        $this->pcall("channels.deleteUserHistory", ['channel' => $chat, 'user_id' => $msgh['id']], true);
                    }
                }
            }
        } catch (\danog\MadelineProto\Exception $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => $e->getMessage()]);
        } catch (\danog\MadelineProto\TL\Exception $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => $e->getMessage()]);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => $e->getMessage()]);
        }

        //participants delete
        try {
            $chatinfo = $this->get_pwr_chat($chat);
            foreach ($chatinfo['participants'] as $part) {
                $this->pcall("channels.deleteUserHistory", ['channel' => $chat, 'user_id' => $part['user']['id']], true);
            }
        } catch (\danog\MadelineProto\Exception $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => $e->getMessage()]);
        } catch (\danog\MadelineProto\TL\Exception $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => $e->getMessage()]);
        } catch (\danog\MadelineProto\RPCErrorException $e) {
            $this->messages->sendMessage(['peer' => self::logchannel, 'text' => $e->getMessage()]);
        }
    }
    //*/

    private function deleteMessages($chatID, $mids)
    {
        return (bool)$this->pcall("channels.deleteMessages", ['channel' => $chatID, 'id' => $mids], true);
    }

    public function joinchat($invite)
    {
        if (preg_match("#joinchat/([\w\d_\-]+)#i", $invite, $res)) {
            return $this->pcall("messages.importChatInvite", ['hash' => $res[1]]);
        } elseif (preg_match("|me/([\w\d_]+)|", $invite, $res) || preg_match("|@([\w\d_]+)|", $invite, $res)) {
            return $this->pcall("channels.joinChannel", ['channel' => $res[1]]);
        }
    }

    public function leavechat($id)
    {
        return $this->pcall("channels.leaveChannel", ['channel' => $id]);
    }

    public function sm($chatID, $text)
    {
        return $this->pcall("messages.sendMessage", ['peer' => $chatID, 'text' => $text]);
    }
}

$session = isset($argv[1]) ? $argv[1] : die("Specificare una sessione\n");

$MadelineProto = new \danog\MadelineProto\API($session);
$MadelineProto->settings['serialization']['serialization_interval'] = PHP_INT_MAX; //don't serialzie
$MadelineProto->start();
$MadelineProto->setEventHandler('\EventHandler');
$MadelineProto->loop();
