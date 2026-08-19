<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration\api;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Flock\Plan;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Plans are a price list: readable by anyone who could decide to pay for one,
 * writable by the owner alone. And a plan that grants nothing is not a plan,
 * so creating one creates the group it grants.
 */
class PlansTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-flock');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
        ]);
    }

    /** @test */
    #[Test]
    public function an_admin_can_create_a_plan(): void
    {
        $response = $this->send($this->create(1));

        $this->assertEquals(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame('Supporter', $body['data']['attributes']['name']);
        $this->assertSame(500, $body['data']['attributes']['amount']);
    }

    /**
     * The access primitive arrives with the plan. Without it the owner has sold
     * something that grants nothing.
     *
     * @test
     */
    #[Test]
    public function creating_a_plan_creates_the_group_it_grants(): void
    {
        $this->send($this->create(1));

        $plan = Plan::query()->firstOrFail();

        $this->assertNotNull($plan->group_id, 'the plan points at a group');

        $group = Group::query()->find($plan->group_id);

        $this->assertNotNull($group);
        $this->assertSame('Supporter', $group->name_singular);
        $this->assertTrue((bool) $group->is_hidden, 'a paid tier is not a badge unless the owner says so');
    }

    /** @test */
    #[Test]
    public function a_member_cannot_create_a_plan(): void
    {
        $this->assertEquals(403, $this->send($this->create(2))->getStatusCode());
    }

    /**
     * Which gate stops a guest is not the point, and asserting on the code
     * would test the wrong thing: an unauthenticated request is refused by CSRF
     * before authorization is ever consulted. What matters is that nothing is
     * created.
     *
     * @test
     */
    #[Test]
    public function a_guest_cannot_create_a_plan(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/flock-plans')->withParsedBody($this->body())
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame(0, Plan::query()->count());
    }

    /**
     * A retired plan stays visible to the owner and disappears for everyone
     * else, so it stops being sold without being deleted.
     *
     * @test
     */
    #[Test]
    public function a_retired_plan_is_hidden_from_members_and_kept_for_the_owner(): void
    {
        $this->send($this->create(1));

        Plan::query()->update(['is_active' => false]);

        $this->assertCount(0, $this->index(2), 'a member sees nothing on sale');
        $this->assertCount(1, $this->index(1), 'the owner still has it');
    }

    /**
     * The owner's Stripe ids are their account's business, and no member has
     * any use for them.
     *
     * @test
     */
    #[Test]
    public function stripe_ids_are_not_shown_to_members(): void
    {
        $this->send($this->create(1));

        Plan::query()->update(['stripe_price_id' => 'price_secret', 'stripe_product_id' => 'prod_secret']);

        $member = $this->index(2)[0]['attributes'];
        $admin = $this->index(1)[0]['attributes'];

        $this->assertArrayNotHasKey('stripePriceId', $member);
        $this->assertSame('price_secret', $admin['stripePriceId']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function index(int $as): array
    {
        $response = $this->send($this->request('GET', '/api/flock-plans', ['authenticatedAs' => $as]));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data'];
    }

    protected function create(int $as): ServerRequestInterface
    {
        return $this->request('POST', '/api/flock-plans', ['authenticatedAs' => $as])
            ->withParsedBody($this->body());
    }

    /**
     * @return array<string, mixed>
     */
    protected function body(): array
    {
        return [
            'data' => [
                'type' => 'flock-plans',
                'attributes' => [
                    'name' => 'Supporter',
                    'amount' => 500,
                    'currency' => 'usd',
                    'interval' => 'month',
                ],
            ],
        ];
    }
}
