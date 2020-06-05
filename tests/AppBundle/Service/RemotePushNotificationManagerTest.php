<?php

namespace Tests\AppBundle\Service;

use AppBundle\Entity\ApiUser;
use AppBundle\Entity\RemotePushToken;
use AppBundle\Service\RemotePushNotificationManager;
use Kreait\Firebase\Factory as FirebaseFactory;
use Kreait\Firebase\Messaging as FirebaseMessaging;
use Kreait\Firebase\Messaging\CloudMessage;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\NullLogger;
use Pushok;

class RemotePushNotificationManagerTest extends TestCase
{
    use ProphecyTrait;

    private $remotePushNotificationManager;

    private function assertArrayIsZeroIndexed(array $value)
    {
        $this->assertEquals(range(0, count($value) - 1), array_keys($value), 'Failed asserting that an array is zero-indexed');
    }

    private function generateApnsToken()
    {
        $characters = 'abcdef0123456789';

        $token = '';
        $max = strlen($characters) - 1;
        for ($i = 0; $i < 64; $i++) {
            $token .= $characters[mt_rand(0, $max)];
        }

        return $token;
    }

    public function setUp(): void
    {
        $this->firebaseMessaging = $this->prophesize(FirebaseMessaging::class);
        $this->firebaseFactory = $this->prophesize(FirebaseFactory::class);
        $this->pushOkClient = $this->prophesize(Pushok\Client::class);

        $this->firebaseFactory->createMessaging()
            ->willReturn($this->firebaseMessaging->reveal());

        $this->remotePushNotificationManager = new RemotePushNotificationManager(
            $this->firebaseFactory->reveal(),
            $this->pushOkClient->reveal(),
            new NullLogger()
        );
    }

    public function testSendOneWithApns()
    {
        $deviceToken = $this->generateApnsToken();

        $remotePushToken = new RemotePushToken();
        $remotePushToken->setToken($deviceToken);
        $remotePushToken->setPlatform('ios');

        $this->pushOkClient
            ->addNotifications(Argument::that(function (array $notifications) use ($deviceToken) {

                $this->assertCount(1, $notifications);
                $this->assertEquals($deviceToken, $notifications[0]->getDeviceToken());

                $payload = $notifications[0]->getPayload();

                $this->assertEquals('Hello world!', $payload->getAlert()->getTitle());

                return true;
            }))
            ->shouldBeCalled();

        $this->pushOkClient
            ->push()
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', $remotePushToken);
    }

    public function testSendMulitpleWithApns()
    {
        $token1 = $this->generateApnsToken();
        $remotePushToken1 = new RemotePushToken();
        $remotePushToken1->setToken($token1);
        $remotePushToken1->setPlatform('ios');

        $token2 = $this->generateApnsToken();
        $remotePushToken2 = new RemotePushToken();
        $remotePushToken2->setToken($token2);
        $remotePushToken2->setPlatform('ios');

        $this->pushOkClient
            ->addNotifications(Argument::that(function (array $notifications) use ($token1, $token2) {

                $this->assertCount(2, $notifications);

                $deviceTokens = array_map(function (Pushok\Notification $n) {
                    return $n->getDeviceToken();
                }, $notifications);

                $this->assertContains($token1, $deviceTokens);
                $this->assertContains($token2, $deviceTokens);

                return true;
            }))
            ->shouldBeCalled();

        $this->pushOkClient
            ->push()
            ->willReturn([]);

        $this->remotePushNotificationManager->send('Hello world!', [
            $remotePushToken1,
            $remotePushToken2
        ]);

        $this->pushOkClient
            ->push()
            ->shouldHaveBeenCalled();
    }

    public function testSendOneWithFcm()
    {
        $token = $this->generateApnsToken();

        $remotePushToken = new RemotePushToken();
        $remotePushToken->setToken($token);
        $remotePushToken->setPlatform('android');

        $this->firebaseMessaging
            ->sendMulticast(Argument::that(function (CloudMessage $message) {

                $data = json_decode(json_encode($message), true);

                $this->assertArrayHasKey('notification', $data);
                $this->assertArrayHasKey('android', $data);

                $this->assertEquals('Hello world!', $data['notification']['title']);
                $this->assertEquals('Hello world!', $data['notification']['body']);

                return true;
            }), [ $token ])
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', $remotePushToken);
    }

    public function testSendMultipleWithFcm()
    {
        $token1 = $this->generateApnsToken();
        $remotePushToken1 = new RemotePushToken();
        $remotePushToken1->setToken($token1);
        $remotePushToken1->setPlatform('android');

        $token2 = $this->generateApnsToken();
        $remotePushToken2 = new RemotePushToken();
        $remotePushToken2->setToken($token2);
        $remotePushToken2->setPlatform('android');

        $this->firebaseMessaging
            ->sendMulticast(Argument::that(function (CloudMessage $message) {

                $data = json_decode(json_encode($message), true);

                $this->assertArrayHasKey('notification', $data);
                $this->assertArrayHasKey('android', $data);

                $this->assertEquals('Hello world!', $data['notification']['title']);
                $this->assertEquals('Hello world!', $data['notification']['body']);

                return true;
            }), [ $token1, $token2 ])
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', [
            $remotePushToken1,
            $remotePushToken2
        ]);
    }

    public function testSendMultipleWithMissingToken()
    {
        $token1 = $this->generateApnsToken();
        $token2 = $this->generateApnsToken();

        $remotePushToken1 = new RemotePushToken();
        $remotePushToken1->setToken($token1);
        $remotePushToken1->setPlatform('android');

        $remotePushToken2 = new RemotePushToken();
        $remotePushToken2->setToken($token2);
        $remotePushToken2->setPlatform('android');

        $user1 = new ApiUser();
        $user1->getRemotePushTokens()->add($remotePushToken1);

        $user2 = new ApiUser();
        $user2->getRemotePushTokens()->add($remotePushToken2);

        $user3 = new ApiUser();

        $this->firebaseMessaging
            ->sendMulticast(Argument::that(function (CloudMessage $message) {

                $data = json_decode(json_encode($message), true);

                $this->assertArrayHasKey('notification', $data);
                $this->assertArrayHasKey('android', $data);

                $this->assertEquals('Hello world!', $data['notification']['title']);
                $this->assertEquals('Hello world!', $data['notification']['body']);

                return true;
            }), [ $token1, $token2 ])
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', [
            $user1,
            $user2,
            $user3
        ]);
    }

    public function testSendMultipleWithMixedTokens()
    {
        $token1 = $this->generateApnsToken();
        $token2 = $this->generateApnsToken();
        $token3 = $this->generateApnsToken();

        $remotePushToken1 = new RemotePushToken();
        $remotePushToken1->setToken($token1);
        $remotePushToken1->setPlatform('ios');

        $remotePushToken2 = new RemotePushToken();
        $remotePushToken2->setToken($token2);
        $remotePushToken2->setPlatform('android');

        $remotePushToken3 = new RemotePushToken();
        $remotePushToken3->setToken($token3);
        $remotePushToken3->setPlatform('android');

        $user1 = new ApiUser();
        $user1->getRemotePushTokens()->add($remotePushToken1);

        $user2 = new ApiUser();
        $user2->getRemotePushTokens()->add($remotePushToken2);

        $user3 = new ApiUser();
        $user3->getRemotePushTokens()->add($remotePushToken3);

        $this->pushOkClient
            ->addNotifications(Argument::that(function (array $notifications) use ($token1, $token2) {

                $this->assertCount(1, $notifications);

                return true;
            }))
            ->shouldBeCalled();

        $this->pushOkClient
            ->push()
            ->willReturn([]);

        $this->firebaseMessaging
            ->sendMulticast(Argument::that(function (CloudMessage $message) {

                $data = json_decode(json_encode($message), true);

                $this->assertArrayHasKey('notification', $data);
                $this->assertArrayHasKey('android', $data);
                $this->assertArrayHasKey('data', $data);

                $this->assertEquals('Hello world!', $data['notification']['title']);
                $this->assertEquals('Hello world!', $data['notification']['body']);

                $this->assertArrayHasKey('event', $data['data']);
                $this->assertEquals('{"name":"foo"}', $data['data']['event']);

                return true;
            }), [ $token2, $token3 ])
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', [
            $user1,
            $user2,
            $user3
        ], ['event' => [ 'name' => 'foo' ]]);

        $this->pushOkClient
            ->push()
            ->shouldHaveBeenCalled();
    }
}
