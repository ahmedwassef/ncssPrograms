<?php

namespace Drupal\sdp_integration\Service;

use GuzzleHttp\Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

class SDPService {

  protected $client;
  protected $configFactory;
  protected $currentUser;
  protected $requestStack;
  protected $entityTypeManager;
  protected $logger;

  public function __construct(
    Client $client,
    ConfigFactoryInterface $configFactory,
    AccountProxyInterface $currentUser,
    RequestStack $requestStack,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->client = $client;
    $this->configFactory = $configFactory;
    $this->currentUser = $currentUser;
    $this->requestStack = $requestStack;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerFactory->get('sdp_integration');
  }

  public function getUserInfo($accessToken) {
    $config = $this->configFactory->get('sdp_integration.settings');
    $url = $config->get('sdp_base_url') . $config->get('sdp_user_info_endpoint');

    $response = $this->client->post($url, [
      'json' => ['accessToken' => $accessToken],
      'headers' => ['Content-Type' => 'application/json']
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  public function isAuthorized($accessToken) {
    $config = $this->configFactory->get('sdp_integration.settings');
    $url = $config->get('sdp_base_url') . $config->get('sdp_auth_endpoint');

    $response = $this->client->post($url, [
      'json' => ['accessToken' => $accessToken],
      'headers' => ['Content-Type' => 'application/json']
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Auto-authenticate user from cookie access token.
   */
  public function autoAuthenticateFromCookie() {


    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      $this->redirectTo401();
      return;
    }

    // Skip if current route is 'need-login'
    $current_route = $request->attributes->get('_route');
    if ($current_route === 'ncss_about_block.needLogin') {
      return;
    }


    // Skip if user is already logged in
    if ($this->currentUser->isAuthenticated()) {
      return;
    }

    // Get access token from cookie
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      $this->redirectTo401();
      return;
    }

    $accessToken = $request->cookies->get('sdp_access_token');
    if (empty($accessToken)) {
      $this->redirectTo401();
      return;
    }

    // Get user info from SDP
    try {
      $userInfo = $this->getUserInfo($accessToken);
      if (empty($userInfo) || empty($userInfo['email'])) {
        $this->logger->warning('Invalid user info received from SDP');
        $this->redirectTo401();
        return;
      }

      // Create or update user and log them in
      $user = $this->createOrUpdateUser($userInfo);
      if ($user) {
        $this->loginUser($user);
        $this->logger->info('User @email automatically logged in via SDP', ['@email' => $userInfo['email']]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Auto authentication failed: @message', ['@message' => $e->getMessage()]);
      $this->redirectTo401();
    }
  }

  /**
   * Redirect to 401 unauthorized page.
   */
  protected function redirectTo401() {
   $response = new RedirectResponse('/need-login', 302);
    $response->send();
    return null;
    exit();
  }

  /**
   * Create or update user based on SDP user info.
   */
  protected function createOrUpdateUser(array $userInfo) {
    $email = $userInfo['email'];
    $storage = $this->entityTypeManager->getStorage('user');

    // Try to find existing user by email
    $users = $storage->loadByProperties(['mail' => $email]);

    if (!empty($users)) {
      // Update existing user
      $user = reset($users);
      $this->updateUserFromSDP($user, $userInfo);
    }
    else {
      // Create new user
      $user = $this->createUserFromSDP($userInfo);
    }

    return $user;
  }

  /**
   * Create new user from SDP info.
   */
  protected function createUserFromSDP(array $userInfo) {
    $user = User::create([
      'name' => $userInfo['email'], // Use email as username
      'mail' => $userInfo['email'],
      'status' => 1,
      'field_first_name' => $userInfo['firstName'] ?? '',
      'field_last_name' => $userInfo['lastName'] ?? '',
      'field_sdp_user_id' => $userInfo['id'] ?? '',
    ]);

    $user->save();
    $this->logger->info('New user created from SDP: @email', ['@email' => $userInfo['email']]);

    return $user;
  }

  /**
   * Update existing user from SDP info.
   */
  protected function updateUserFromSDP(User $user, array $userInfo) {
    $updated = FALSE;

    // Update first name if provided and different
    if (!empty($userInfo['firstName']) && $user->get('field_first_name')->value !== $userInfo['firstName']) {
      $user->set('field_first_name', $userInfo['firstName']);
      $updated = TRUE;
    }

    // Update last name if provided and different
    if (!empty($userInfo['lastName']) && $user->get('field_last_name')->value !== $userInfo['lastName']) {
      $user->set('field_last_name', $userInfo['lastName']);
      $updated = TRUE;
    }

    // Update SDP user ID if provided and different
    if (!empty($userInfo['id']) && $user->get('field_sdp_user_id')->value !== $userInfo['id']) {
      $user->set('field_sdp_user_id', $userInfo['id']);
      $updated = TRUE;
    }

    if ($updated) {
      $user->save();
      $this->logger->info('User updated from SDP: @email', ['@email' => $userInfo['email']]);
    }

    return $user;
  }

  /**
   * Log in the given user.
   */
  protected function loginUser(User $user) {
    // Switch to the user account
    $this->currentUser->setAccount($user);

    // Start user session
    user_login_finalize($user);
  }

}
