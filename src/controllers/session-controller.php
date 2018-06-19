<?php
/*
 * Session controller class to handle all session related operations
 */ 
class SessionController extends ControllerBase
{
  private function createHash($password)
  {
    return hash('md5', $password);
  }

  // Get all sessions from db
  // URL: /api/session/active
  public function active()
  {
    // Create query finding all active sessions
    $query = $this->entityManager->createQuery('SELECT s.id, s.name, s.isPrivate, count(m.id) memberCount  FROM Session s LEFT JOIN s.members m WHERE s.lastAction > ?1 GROUP BY s.id');
    $query->setParameter(1, new DateTime('-1 hour'));
    $sessions = $query->getArrayResult();
    return $sessions;
  }
  
  // Create session with name and private flag
  // URL: /api/session/create
  public function create()
  {
    $data = $this->jsonInput();        

    $session = new Session();
    $session->setName($data["name"]);
    $session->setCardSet($data["cardSet"]);

    $private = $data["isPrivate"];
    $session->setIsPrivate($private);
    if ($private)
      $session->setPassword($this->createHash($data["password"]));

    // Generate the access token and assign it to the session
    $token = bin2hex(random_bytes(8));
    $session->setToken($token);
      
    $session->setLastAction(new DateTime());

    $this->save($session);
    
    $this->setCookie($session);
    
    return new NumericResponse($session->getId());
  }

  // Add or remove member
  // URL: /api/session/member/{id}/?{mid}
  public function member($sessionId, $memberId = 0)
  {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method == "PUT")
    {           
      return $this->addMember($sessionId);
    }
    if ($method == "DELETE")
    {
      $this->removeMember($memberId);
    }
  }
  
  // Add a member with this name to the session
  private function addMember($id)
  {   
    $data = $this->jsonInput();
    $name = $data["name"];
    
    $session = $this->getSession($id);
    $token = $session->getToken();
    $tokenKey = $this->tokenKey($session->getId());

    // Check if token was passed
    if(isset($_GET["token"]) && $_GET["token"] == $token) {
      $this->setCookie($session);
    // Or the password
    } else if(isset($data["password"]) && $session->getPassword() === $this->createHash($data["password"])) {
      $this->setCookie($session);
    // Or the user already has the token
    } else if(isset($_COOKIE[$tokenKey]) && $_COOKIE[$tokenKey] == $token) {
      // Do nothing and continue
    } else {
      return null;
    }
    
    // Check for existing member
    foreach($session->getMembers() as $candidate)
    {
      if($candidate->getName() == $name)
      {
        $member = $candidate;
        break;
      }  
    }

    // Create new member
    if(!isset($member))
    {
      $member = new Member();
      $member->setName($name);
      $member->setSession($session);
      $session->setLastAction(new DateTime());
        
      $this->saveAll([$member, $session]);
    }
    
    $result = new stdClass();
    $result->sessionId = $id;
    $result->memberId = $member->getId();
    return $result;
  }
  
  // Remove member from session
  private function removeMember($id)
  {
    include __DIR__ .  "/session-evaluation.php";

    // Get and remove member
    $member = $this->getMember($id);    
    $this->entityManager->remove($member);
    $this->entityManager->flush();

    // Get the members session and its current poll
    $session = $member->getSession();
    $poll = $session->getCurrentPoll();
    if($poll !== null && SessionEvaluation::evaluatePoll($session, $poll))
    {
      $cardSet = $this->getCardSet($session);
      SessionEvaluation::highlightVotes($session, $poll, $cardSet);
    }

    // Update session to trigger polling
    $session->setLastAction(new DateTime());
    $this->save($session);

    $this->entityManager->flush();
  }
  
  // Check if session is protected by password
  // URL: /api/session/haspassword/{id}
  public function haspassword($id)
  {
    $session = $this->getSession($id);
    return new BoolResponse($session->getIsPrivate());
  }

  // Check if member is still part of the session
  // URL: /api/session/membercheck/{id}/{mid}
  public function membercheck($sid, $mid)
  {
    $session = $this->getSession($sid);
    foreach($session->getMembers() as $member) {
      if($member->getId() == $mid) {
        return new BoolResponse(true);
      }
    }
    return new BoolResponse();
  }
  
  // Check given password for a session
  // URL: /api/session/check/{id}
  public function check($id)
  {
    $data = $this->jsonInput();
    $session = $this->getSession($id);
    $result = $session->getPassword() === $this->createHash($data["password"]);

    // If the correct password was transmitted we also assign the token
    if ($result)
      $this->setCookie($session);

    return new BoolResponse($result);
  }

  // Get the card set of this session
  // URL: /api/session/cardset/{id}
  public function cardset($id)
  {
    $session = $this->getSession($id);
    return $this->getCardSet($session);
  }

  // Get the card set of this session
  // URL: /api/session/cardsets
  public function cardsets()
  {
    return $this->cardSets;
  }

  // Set the token cookie for this session 
  // with additional parameters for expiration and path
  private function setCookie($session)
  {
    $tokenKey = $this->tokenKey($session->getId());
    setcookie($tokenKey, $session->getToken(), time()+60*60*24*30, "/");
  }
}

return new SessionController($entityManager, $cardSets);
