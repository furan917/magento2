<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Controller\Guest;

use Magento\TestFramework\Request;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Test for \Magento\Sales\Controller\Guest\View class.
 */
class ViewTest extends AbstractController
{
    /**
     * Check that controller applied only POST requests.
     */
    public function testExecuteWithNonPostRequest()
    {
        $this->getRequest()->setMethod(Request::METHOD_GET);
        $this->dispatch('sales/guest/view/');

        $this->assert404NotFound();
    }
}
