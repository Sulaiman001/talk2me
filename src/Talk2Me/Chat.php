<?php
namespace Talk2Me;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

if (file_exists(__DIR__ . "/CommandPlugin.php")) { 
    require_once(__DIR__ . "/CommandPlugin.php");
}

class Chat implements MessageComponentInterface {

    protected $clients;
    private $rooms;
    private $roomUsers;
    private $users;
    private $mongo;
    private $db;
    private $dbRooms;
    private $dbMessages;
    private $moreMessagesLimit = 20;
    private $allowPersistentRooms = false;
    private $cfg;

    public function __construct($cfg, $mongo) {
        $this->allowPersistentRooms = $cfg['allowPersistentRooms'];
        $this->mongo = $mongo;
        if ($this->allowPersistentRooms) {
            $this->db = $this->mongo->$cfg['mongoDatabase'];
            $this->dbRooms = $this->db->rooms;
            $this->dbMessages = $this->db->messages;
        }
        $this->clients = new \SplObjectStorage;
        $this->cfg = $cfg;
        $this->users = array();
        foreach ($this->users as $k=>$v) {
            unset($this->users[$k]);
        }
        unset($this->rooms);
        unset($this->roomUsers);
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $json = json_decode($msg);

        if ($json->a === "login") {

            // Handle login
            $this->handleLogin($from, $json, $msg);

            return;

        } else if ($json->a === "message") {

            // Handle sending messages.
            $this->handleMessage($from, $json);

            return;

        } else if ($this->allowPersistentRooms && $json->a === "moreMessages") {

            if ($json->persistent) {
                $r = $this->dbRooms->findOne(array("name" => $this->getRoom($from)));
                if (!isset($r) || is_null($r) || count($r) < 1) {
                    $roomData = array("name" => $json->room);
                    $r = $this->dbRooms->insert($roomData);
                    $rooms_id = $roomData['_id'];
                } else {
                    $rooms_id = $r['_id'];
                }

                $json->a = "showMoreMessages";
                if ($json->encrypted) {
                    $messagesData = array("rooms_id" => $rooms_id, "encrypted" => $json->encrypted);
                } else {
                    $messagesData = array("rooms_id" => $rooms_id, "encrypted" => $json->encrypted);
                }
                $sortData = array("timestamp" => -1);
                $mr = $this->dbMessages->find($messagesData)->sort($sortData)
                        ->skip($json->offset)->limit($this->moreMessagesLimit);
                $messagesArray = array();
                while ($mr->hasNext()) {
                    $item = $mr->getNext();
                    $messagesArray[]['item'] = array("message" => $item['message'],
                            "encrypted" => $item['encrypted']);
                }
                $json->messages = $messagesArray;
                $json->moreMessagesLimit = $this->moreMessagesLimit;
                $from->send(json_encode($json));
            }

            return;

        } else if ($json->a === "who") {

            // Returns who is online
            $this->whoIsOnline($from);

            return;

        } else if ($json->a === "statusChange") {

            $username = $this->getUsername($from);
            $this->setUserStatus($from, $json->status);
            $json->msg = "@" . $username . " went " . $json->status
                    . " <span class=\"timestamp\">" . date("Y-m-d H:i:s") . "</span>";
            $json->statusType = "statusChange";
            $json->username = $username;
            $json->currentStatus = $json->status;
            $this->handleMessage($from, $json, "status-message");

            return;

        } else if ($json->a === "typing") {

            $json->msg = "@" . $this->getUsername($from) . " is typing";
            $this->handleMessage($from, $json, "typing");

            return;
            
        } else if ($this->allowPersistentRooms && $json->a === "persistMessage") {

            $r = $this->dbRooms->findOne(array("name" => $json->room));
            $rooms_id = $r['_id'];

            $messagesData = array("rooms_id" => $rooms_id, "message" => $json->msg, 
                    "timestamp" => date("U") . "-" . microtime(), 
                    "encrypted" => $json->encrypted);
            $this->dbMessages->insert($messagesData);

            return;

        }
    }

    public function handleLogin($from, $json, $msg) {
        $isLoggedIn = $this->isLoggedIn($json->username);

        if ($isLoggedIn) {
            $response = array("status"=>"ok", "a"=>"login", "isLoggedIn"=>true);
            $from->send(json_encode($response));
            return;
        } else {
            $this->setUsers($from, $json->username);

            $response = array("status"=>"ok", "a"=>"login", "isLoggedIn"=>false);
            if ($this->allowPersistentRooms && $json->persistent) {
                $r = $this->dbRooms->findOne(array("name" => $json->room));
                if (!isset($r) || is_null($r) || count($r) < 1) {
                    $roomData = array("name" => $json->room);
                    $r = $this->dbRooms->insert($roomData);
                    $rooms_id = $roomData['_id'];
                } else {
                    $rooms_id = $r['_id'];
                }

                // TODO: If encrypted then leave off encrypted key in $messagesData.
                //       Then in the JavaScript checked the encrypted value and decrypt
                //       the appropriate messages. Anything that is encrypted put a
                //       lock icon in the message. We'll have to also add the lock to
                //       all incoming messages and the ones you send.
                //
                //       Also this code is duplicated. Refactor into a method.
                if ($json->encrypted) {
                    $messagesData = array("rooms_id" => $rooms_id, "encrypted" => $json->encrypted);
                } else {
                    $messagesData = array("rooms_id" => $rooms_id, "encrypted" => $json->encrypted);
                }
                $sortData = array("timestamp" => -1);
                $mr = $this->dbMessages->find($messagesData)->sort($sortData)
                        ->limit($this->moreMessagesLimit);
                $messagesArray = array();
                while ($mr->hasNext()) {
                    $item = $mr->getNext();
                    $messagesArray[]['item'] = array("message" => $item['message'],
                            "encrypted" => $item['encrypted']);
                }

                $response['messages'] = $messagesArray;
                $response['moreMessagesLimit'] = $this->moreMessagesLimit;
            }
            $from->send(json_encode($response));

            $this->setRoom($from, $json->room);
            $this->setUsername($from, $json->username);
            $this->setUserStatus($from, "Free");
            $this->roomUsers[$json->room][$json->username] = $json->username;

            $this->whoIsOnline($from);

            foreach ($this->clients as $client) {
                if ($from !== $client 
                        // Ensure message is sent to the proper room.
                        && $this->getRoom($from) === $this->getRoom($client)) {
                    $o = array("status"=>"ok", "a"=>"message", "t"=>"status-message", 
                            "statusType"=>"join", "username"=>$json->username,
                            "msg"=>"<span style=\"color:green;\">@" 
                            . $json->username . " joined</span> <span class=\"timestamp\">" 
                            . date("Y-m-d H:i:s") . "</span>");
                    $client->send(json_encode($o));
                }
            }
        }
    }

    function handleMessage($from, $json, $t=null) {
        if (!isset($t)) {
            $t = "message";
        }

        if (class_exists("Talk2Me\CommandPlugin")) {
            $cp = new \Talk2Me\CommandPlugin;
            $executed = $cp->execute($this, $from, $json, $t);
            // If a command was executed it is assumed to have been sent back to $from.
            // We don't send commands to clients unless we do it in execute() explicity;
            if ($executed) {
                return;
            }
        }

        $fromUsername = $this->getUsername($from);
        foreach ($this->clients as $client) {
            // Don't send message to the sender.
            if ($from !== $client 
                    // Ensure message is sent to the proper room.
                    && $this->getRoom($from) === $this->getRoom($client)) {
                $json->status = "ok";
                $json->a = "message";
                $json->t = $t;
                $json->from = $fromUsername;
                $client->send(json_encode($json));
            }
        }

        // This sends the message back for persistence.
        if ($this->allowPersistentRooms && $json->persistent) {
            $json->a = "persistMessage";
            $json->room = $this->getRoom($from);
            $this->onMessage($from, json_encode($json));
        }
    }

    public function whoIsOnline($from) {
        $room = $this->getRoom($from);
        $currentMembers = "";
        $users = array();
        if (isset($this->roomUsers[$room])) {
            foreach ($this->roomUsers[$room] as $username) {
                $resourceId = array_search($username, $this->users);
                $status = $this->rooms[$resourceId]['status'];
                if ($status === "Free") {
                    $currentMembers .= "@{$username}, ";
                    $users[$username] = "@{$username}";
                } else {
                    $currentMembers .= "@{$username}.<span class=\"user-status\">{$status}</span>, ";
                    $users[$username] .= "@{$username}.<span class=\"user-status\">{$status}</span>";
                }
            }
        }
        $currentMembers = rtrim($currentMembers, ", ");
        $msg = "<strong style=\"color:green;\">Online</strong> {$currentMembers} <span class=\"timestamp\">" 
                . date("Y-m-d H:i:s") . "</span>";

        $currentMembersObj = array("status"=>"ok", "a"=>"message", "t"=>"who", "msg"=>$msg, "users"=>$users);
        $from->send(json_encode($currentMembersObj));
    }

    public function logout($client) {
        $room = $this->getRoom($client);
        $username = $this->getUsername($client);
        $this->removeFromUsers($username);
        $this->unsetRoomUserClient($client);
        $this->clients->detach($client);

        if (isset($room) && isset($username)) {
            foreach ($this->clients as $theClient) {
                $o = array("status"=>"ok", "a"=>"message", "t"=>"status-message",
                        "statusType"=>"disconnect", "username"=>$username,
                        "msg"=>"<span style=\"color:red;\">@" 
                        . $username . " disconnected</span> <span class=\"timestamp\">" 
                        . date("Y-m-d H:i:s") . "</span>");
                if ($this->getRoom($theClient) === $room) {
                    $theClient->send(json_encode($o));
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->logout($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $this->logout($conn);
        $conn->close();
    }

    public function getUserStatus($client) {
        return $this->rooms[$client->resourceId]['status'];
    }

    public function setUserStatus($client, $status) {
        $this->rooms[$client->resourceId]['status'] = $status;
    }

    public function getRoom($client) {
        return $this->rooms[$client->resourceId]['room'];
    }

    public function setRoom($client, $room) {
        $this->rooms[$client->resourceId]['room'] = $room;
    }

    public function getUsername($client) {
        return $this->rooms[$client->resourceId]['username'];
    }

    public function setUsername($client, $username) {
        $this->rooms[$client->resourceId]['username'] = $username;
    }

    public function unsetRoomUserClient($client) {
        $key = false;
        if (is_array($this->roomUsers[$this->getRoom($client)])) {
            $key = array_search($this->getUsername($client), 
                    $this->roomUsers[$this->getRoom($client)]);
        }
        if ($key) {
            unset($this->roomUsers[$this->rooms[$client->resourceId]['room']][$key]);
            unset($this->rooms[$client->resourceId]);
        }
    }

    public function setUsers($client, $username) {
        $this->users[$client->resourceId] = $username;
    }

    public function getUsers() {
        return $this->users;
    }

    public function removeFromUsers($username) {
        $key = array_search($username, $this->users);
        if ($key) {
            unset($this->users[$key]);
        }
    }

    public function isLoggedIn($username) {
        if (in_array($username, $this->users)) {
            return true;
        } else {
            return false;
        }
    }

}
