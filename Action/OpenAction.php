<?php

namespace Omnimeet\Jitsi\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Jitsi\Api;
use Omnimeet\Model\Status;
use Omnimeet\Request\Open;
use Omnimeet\Request\Request;

/**
 * A Jitsi room is not made beforehand: it exists from the moment its first
 * participant comes in, and is gone when the last one leaves. Opening is
 * naming it - the same key, the same room - and nothing is asked of the
 * instance.
 */
final class OpenAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Open;
    }

    /** @param Open $request */
    public function execute(Request $request): void
    {
        $request->setResult($request->meeting->with($this->api->room($request->meeting), Status::OPEN));
    }
}
