<?php

namespace Drupal\Tests\message_subscribe\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\message\Entity\Message;

/**
 * Test getting subscribes from context.
 *
 * @group message_subscribe
 *
 * @coversDefaultClass \Drupal\message_subscribe\Subscribers
 */
class SubscribersTest extends MessageSubscribeTestBase {

  use AssertMailTrait;

  /**
   * Flag service.
   *
   * @var \Drupal\flag\FlagServiceInterface
   */
  protected $flagService;

  /**
   * The message subscription service.
   *
   * @var \Drupal\message_subscribe\SubscribersInterface
   */
  protected $messageSubscribers;

  /**
   * Nodes to test with.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected $nodes;

  /**
   * Users to test with.
   *
   * @var \Drupal\user\UserInterface[]
   */
  protected $users;

  /**
   * {@inheritdoc}
   */
  public static $modules = ['taxonomy'];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installSchema('node', ['node_access']);

    $this->flagService = $this->container->get('flag');
    $this->messageSubscribers = $this->container->get('message_subscribe.subscribers');

    // Create node-type.
    $node_type = 'article';

    $flags = $this->flagService->getFlags();

    $flag = $flags['subscribe_node'];
    $flag->set('bundles', [$node_type]);
    $flag->enable();
    $flag->save();

    $flag = $flags['subscribe_user'];
    $flag->enable();
    $flag->save();

    $this->users[1] = $this->createUser([
      'flag subscribe_node',
      'unflag subscribe_node',
      'flag subscribe_user',
      'unflag subscribe_user',
    ]);
    $this->users[2] = $this->createUser([
      'flag subscribe_node',
      'unflag subscribe_node',
      'flag subscribe_user',
      'unflag subscribe_user',
    ]);
    // User 3 is blocked.
    $this->users[3] = $this->createUser([
      'flag subscribe_node',
      'unflag subscribe_node',
      'flag subscribe_user',
      'unflag subscribe_user',
    ]);
    $this->users[3]->block();
    $this->users[3]->save();

    // Create node.
    $settings = [];
    $settings['type'] = $node_type;
    $settings['uid'] = $this->users[1];
    $this->nodes[0] = $this->createNode($settings);
    $settings['uid'] = $this->users[2];
    $this->nodes[1] = $this->createNode($settings);

    // User1, User2 and user_blocked flag node1.
    $this->flagService->flag($flags['subscribe_node'], $this->nodes[0], $this->users[1]);
    $this->flagService->flag($flags['subscribe_node'], $this->nodes[0], $this->users[2]);
    $this->flagService->flag($flags['subscribe_node'], $this->nodes[0], $this->users[3]);
    $this->flagService->flag($flags['subscribe_node'], $this->nodes[1], $this->users[3]);
    // User2 flags User1.
    $this->flagService->flag($flags['subscribe_user'], $this->users[1], $this->users[2]);

    // Override default notifiers.
    \Drupal::configFactory()->getEditable('message_subscribe.settings')->set('default_notifiers', [])->save();
  }

  /**
   * Test getting the subscribers list.
   */
  public function testGetSubscribers() {
    $message = Message::create([
      'template' => $this->template->id(),
      'uid' => $this->users[1],
    ]);

    $node = $this->nodes[0];
    $user2 = $this->users[2];

    $user_blocked = $this->users[3];
    $uids = $this->messageSubscribers->getSubscribers($node, $message);

    // Assert subscribers data.
    $expected_uids = [
      $user2->id() => [
        'notifiers' => [],
        'flags' => [
          'subscribe_node',
          'subscribe_user',
        ],
      ],
    ];

    $this->assertEquals($uids, $expected_uids, 'All expected subscribers were fetched.');

    // Test none of users will get message if only blocked user is subscribed.
    $message = Message::create([
      'template' => $this->template->id(),
      'uid' => $this->users[1],
    ]);

    $node1 = $this->nodes[1];

    $uids = $this->messageSubscribers->getSubscribers($node1, $message);

    // Assert subscribers data.
    $expected_uids = [];

    $this->assertEquals($uids, $expected_uids, 'All expected subscribers were fetched.');

    // Test notifying all users, including those who are blocked.
    $subscribe_options['notify blocked users'] = TRUE;
    $uids = $this->messageSubscribers->getSubscribers($node, $message, $subscribe_options);

    $expected_uids = [
      $user2->id() => [
        'notifiers' => [],
        'flags' => [
          'subscribe_node',
          'subscribe_user',
        ],
      ],
      $user_blocked->id() => [
        'notifiers' => [],
        'flags' => [
          'subscribe_node',
        ],
      ],
    ];
    $this->assertEquals($uids, $expected_uids, 'All expected subscribers were fetched, including blocked users.');

    $user3 = $this->createUser([
      'flag subscribe_node',
      'unflag subscribe_node',
      'flag subscribe_user',
      'unflag subscribe_user',
    ]);
    $user4 = $this->createUser([
      'flag subscribe_node',
      'unflag subscribe_node',
      'flag subscribe_user',
      'unflag subscribe_user',
    ]);

    $flags = $this->flagService->getFlags();
    $this->flagService->flag($flags['subscribe_node'], $node, $user3);
    $this->flagService->flag($flags['subscribe_node'], $node, $user4);

    // Get subscribers from a given "last uid".
    $subscribe_options = ['last uid' => $user2->id()];
    $uids = $this->messageSubscribers->getSubscribers($node, $message, $subscribe_options);
    $this->assertEquals(array_keys($uids), [$user3->id(), $user4->id()], 'All subscribers from "last uid" were fetched.');

    // Get a range of subscribers.
    $subscribe_options['range'] = 1;
    $uids = $this->messageSubscribers->getSubscribers($node, $message, $subscribe_options);
    $this->assertEquals(array_keys($uids), [$user3->id()], 'All subscribers from "last uid" and "range" were fetched.');
  }

  /**
   * Testing the exclusion of the entity author from the subscribers lists.
   */
  public function testGetSubscribersExcludeSelf() {
    // Test the affect of the variable when set to FALSE (do not notify self).
    \Drupal::configFactory()->getEditable('message_subscribe.settings')->set('notify_own_actions', FALSE)->save();
    $message = Message::create([
      'template' => $this->template->id(),
      'uid' => $this->users[1],
    ]);

    $node = $this->nodes[0];
    $user1 = $this->users[1];
    $user2 = $this->users[2];

    $uids = $this->messageSubscribers->getSubscribers($node, $message);

    // Assert subscribers data.
    $expected_uids = [
      $this->users[2]->id() => [
        'notifiers' => [],
        'flags' => [
          'subscribe_node',
          'subscribe_user',
        ],
      ],
    ];
    $this->assertEquals($uids, $expected_uids, 'All subscribers except for the triggering user were fetched.');

    // Test the affect of the variable when set to TRUE (Notify self).
    \Drupal::configFactory()->getEditable('message_subscribe.settings')->set('notify_own_actions', TRUE)->save();

    $uids = $this->messageSubscribers->getSubscribers($node, $message);

    // Assert subscribers data.
    $expected_uids = [
      $user1->id() => [
        'notifiers' => [],
        'flags' => [
          'subscribe_node',
        ],
      ],
      $user2->id() => [
        'notifiers' => [],
        'flags' => [
          'subscribe_node',
          'subscribe_user',
        ],
      ],
    ];
    $this->assertEquals($uids, $expected_uids, 'All subscribers including the triggering user were fetched.');
  }

  /**
   * Assert subscribers list is entity-access aware.
   */
  public function testEntityAccess() {
    // Make sure we are notifying ourselves for this test.
    \Drupal::configFactory()->getEditable('message_subscribe.settings')->set('notify_own_actions', TRUE)->save();

    $message = Message::create(['template' => $this->template->id()]);

    $node = $this->nodes[0];
    $node->setPublished(FALSE);
    $node->save();

    // Add permission to view own unpublished content.
    user_role_change_permissions(AccountInterface::AUTHENTICATED_ROLE, ['view own unpublished content' => TRUE]);

    // Set the node to be unpublished.
    $user1 = $this->users[1];
    $user2 = $this->users[2];

    $subscribe_options['entity access'] = TRUE;
    $uids = $this->messageSubscribers->getSubscribers($node, $message, $subscribe_options);
    $this->assertEquals(array_keys($uids), [$user1->id()], 'Only user with access to node returned for subscribers list.');

    $subscribe_options['entity access'] = FALSE;
    $uids = $this->messageSubscribers->getSubscribers($node, $message, $subscribe_options);
    $this->assertEquals(array_keys($uids), [$user1->id(), $user2->id()], 'All users (even without access) returned for subscribers list.');
  }

  /**
   * Ensure hooks are firing correctly.
   */
  public function testHooks() {
    $this->enableModules(['message_subscribe_test']);

    $message = Message::create([
      'template' => $this->template->id(),
      'uid' => $this->users[1],
    ]);

    // Create a 4th user that the test module will add.
    $this->users[4] = $this->createUser();

    $node = $this->nodes[0];
    $uids = $this->messageSubscribers->getSubscribers($node, $message);
    // @see message_subscribe_test.module
    $this->assertTrue(\Drupal::state('message_subscribe_test')->get('hook_called'));
    $this->assertTrue(\Drupal::state('message_subscribe_test')->get('alter_hook_called'));
    $this->assertEquals([
      4 => [
        'flags' => ['foo_flag'],
        'notifiers' => ['sms'],
      ],
      10001 => [
        'flags' => ['bar_flag'],
        'notifiers' => ['email'],
      ],
    ], $uids);
  }

  /**
   * Tests sendMessage method.
   *
   * @covers ::sendMessage
   */
  public function testSendMessage() {
    // Enable a notifier.
    $this->config('message_subscribe.settings')
      // Override default notifiers.
      ->set('default_notifiers', ['email'])
      ->save();

    // Add a few more users.
    $flags = $this->flagService->getFlags();
    foreach (range(4, 10) as $i) {
      $this->users[$i] = $this->createUser([
        'access content',
        'flag subscribe_node',
        'unflag subscribe_node',
        'flag subscribe_user',
        'unflag subscribe_user',
      ]);

      $this->flagService->flag($flags['subscribe_node'], $this->nodes[0], $this->users[$i]);
    }

    // Send notifications for node 1.
    // Pass in the save message argument to the notifier.
    $notify_options = [
      'email' => [
        'save on fail' => TRUE,
        'save on success' => TRUE,
      ],
    ];
    $subscribe_options = [
      'notify message owner' => TRUE,
    ];
    $message = Message::create(['template' => $this->template->id()]);
    $this->messageSubscribers->sendMessage($this->nodes[0], $message, $notify_options, $subscribe_options);

    // Verify that each of the users has a copy of the message.
    $mails = $this->getMails();
    $no_message_count = $message_count = 0;
    foreach ($this->users as $account) {
      /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
      $query = $this->container->get('entity_type.manager')->getStorage('message')->getQuery();
      $query->condition('uid', $account->id());
      $result = $query->execute();

      // Users 2 through 3 won't have access.
      if (!$account->hasPermission('access content') || $account->isBlocked()) {
        $this->assertEmpty($result);
        $no_message_count++;
      }
      else {
        $this->assertEquals(1, count($result), '1 message was saved for user ' . $account->id());
        $message_count++;
      }
    }
    $this->assertEquals(2, $no_message_count);
    $this->assertEquals(8, $message_count);
    $this->assertEquals(count($mails), $message_count);
  }

  /**
   * Tests entity owner sending specific to node entites.
   *
   * @covers ::getSubscribers
   */
  public function testNotifyEntityOwner() {
    // Unblock user 3.
    $this->users[3]->activate();
    $this->users[3]->save();

    // Setup a node owned by user 2, but *edited* by user 3.
    $this->nodes[0]->setOwner($this->users[2]);
    $this->nodes[0]->setRevisionUser($this->users[3]);
    $this->nodes[0]->save();

    // Ensure owners are not setup to be notified.
    $this->config('message_subscribe.settings')
      ->set('notify_own_actions', FALSE)
      ->save();

    // User 3, also subscribed, should not be notified. User 2 *should* be
    // notified (they are subscribed in ::setUp) because user 3 edited the node.
    $message = Message::create(['template' => $this->template->id()]);
    $subscribers = $this->messageSubscribers->getSubscribers($this->nodes[0], $message);
    $expected = [
      $this->users[1]->id() => [
        'notifiers' => [],
        'flags' => ['subscribe_node'],
      ],
      $this->users[2]->id() => [
        'notifiers' => [],
        'flags' => ['subscribe_node'],
      ],
    ];
    $this->assertEquals($expected, $subscribers);

    // Edit the node by user 2, and user 3 should now be notified.
    $this->nodes[0]->setRevisionUser($this->users[2]);
    $this->nodes[0]->save();
    $subscribers = $this->messageSubscribers->getSubscribers($this->nodes[0], $message);
    $expected = [
      $this->users[1]->id() => [
        'notifiers' => [],
        'flags' => ['subscribe_node'],
      ],
      $this->users[3]->id() => [
        'notifiers' => [],
        'flags' => ['subscribe_node'],
      ],
    ];
    $this->assertEquals($expected, $subscribers);
  }

}
