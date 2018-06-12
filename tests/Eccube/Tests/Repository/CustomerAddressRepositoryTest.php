<?php

namespace Eccube\Tests\Repository;

use Eccube\Tests\EccubeTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CustomerAddressRepository test cases.
 *
 * @author Kentaro Ohkouchi
 */
class CustomerAddressRepositoryTest extends EccubeTestCase
{
    protected $Customer;

    public function setUp()
    {
        parent::setUp();
        $this->Customer = $this->createCustomer();
    }

    public function testFindOrCreateByCustomerAndId()
    {
        $CustomerAddress = $this->app['eccube.repository.customer_address']->findOrCreateByCustomerAndId($this->Customer, null);
        $this->assertNotNull($CustomerAddress);

        $faker = $this->getFaker();

        $CustomerAddress
            ->setName01($faker->lastName)
            ->setName02($faker->firstName);
        $this->app['orm.em']->persist($CustomerAddress);
        $this->app['orm.em']->flush();

        $id = $CustomerAddress->getId();
        $this->assertNotNull($id);

        $ExistsCustomerAddress = $this->app['eccube.repository.customer_address']->findOrCreateByCustomerAndId($this->Customer, $id);
        $this->assertNotNull($ExistsCustomerAddress);

        $this->expected = $id;
        $this->actual = $ExistsCustomerAddress->getId();
        $this->verify('ID は'.$this->expected.'ではありません');
        $this->assertSame($this->Customer, $ExistsCustomerAddress->getCustomer());
    }

    public function testFindOrCreateByCustomerAndIdWithException()
    {
        try {
            $CustomerAddress = $this->app['eccube.repository.customer_address']->findOrCreateByCustomerAndId($this->Customer, 9999);
            $this->fail();
        } catch (NotFoundHttpException $e) {
            $this->expected = $e->getStatusCode();
            $this->actual = '404';
            $this->verify();
        }
    }

    public function testDeleteByCustomerAndId()
    {
        $CustomerAddress = $this->app['eccube.repository.customer_address']->findOrCreateByCustomerAndId($this->Customer, null);
        $this->app['orm.em']->persist($CustomerAddress);
        $this->app['orm.em']->flush();

        $result = $this->app['eccube.repository.customer_address']->deleteByCustomerAndId($this->Customer, $CustomerAddress->getId());
        $this->assertTrue($result);
    }

    public function testDeleteByCustomerAndIdWithException()
    {
        $result = $this->app['eccube.repository.customer_address']->deleteByCustomerAndId($this->Customer, 9999);
        $this->assertFalse($result);
    }
}
