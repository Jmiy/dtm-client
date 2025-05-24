<?php

declare(strict_types=1);
/**
 * This file is part of DTM-PHP.
 *
 * @license  https://github.com/dtm-php/dtm-client/blob/master/LICENSE
 */

namespace DtmClient;

use DtmClient\Api\ApiInterface;
use DtmClient\Api\RequestBranch;
use DtmClient\Constants\Branch;
use DtmClient\Constants\Operation;
use DtmClient\Constants\Protocol;
use DtmClient\Constants\TransType;
use DtmClient\DbTransaction\DBTransactionInterface;
use DtmClient\Exception\InvalidArgumentException;
use DtmClient\Exception\UnsupportedException;
use Google\Protobuf\Internal\Message;

class XA extends AbstractTransaction
{
    protected Barrier $barrier;

    protected BranchIdGeneratorInterface $branchIdGenerator;

    protected DtmImp $dtmImp;

    protected DBTransactionInterface $dbTransaction;

    public function __construct(ApiInterface $api, Barrier $barrier, BranchIdGeneratorInterface $branchIdGenerator, DtmImp $dtmImp, DBTransactionInterface $dbTransaction)
    {
        $this->api = $api;
        $this->barrier = $barrier;
        $this->branchIdGenerator = $branchIdGenerator;
        $this->dtmImp = $dtmImp;
        $this->dbTransaction = $dbTransaction;
    }

    /**
     * Start a xa local transaction.
     */
    public function localTransaction(callable $callback): mixed
    {
//        var_dump(__METHOD__);

        if (TransContext::getOp() == Branch::BranchCommit || TransContext::getOp() == Branch::BranchRollback) {
            $this->dtmImp->xaHandlePhase2(TransContext::getGid(), TransContext::getBranchId(), TransContext::getOp());
            return null;
        }

        return $this->dtmImp->xaHandleLocalTrans(function () use ($callback) {
            $result = $callback($this->dbTransaction);
            switch ($this->api->getProtocol()) {
                case Protocol::GRPC:
                    $body = [
                        'BranchID' => TransContext::getBranchId(),
                        'Gid' => TransContext::getGid(),
                        'TransType' => TransType::XA,
                        'Data' => ['url' => TransContext::getPhase2URL()],
                    ];
                    break;
                case Protocol::HTTP:
                case Protocol::JSONRPC_HTTP:
                    $body = [
                        'data' => TransContext::getCustomData(),
                        'url' => TransContext::getPhase2URL(),
                        'branch_id' => TransContext::getBranchId(),
                        'gid' => TransContext::getGid(),
                        'trans_type' => TransType::XA,
                    ];
                    break;
                default:
                    throw new UnsupportedException('Unsupported protocol');
            }
            $this->api->registerBranch($body);

            return $result;
        });
    }

    public function callBranch(string $url, array|Message $body, ?array $rpcReply = null)
    {
//        var_dump(__METHOD__);

        $subBranch = $this->branchIdGenerator->generateSubBranchId();
//        var_dump($subBranch);

        switch ($this->api->getProtocol()) {
            case Protocol::HTTP:
            case Protocol::JSONRPC_HTTP:
                $requestBranch = new RequestBranch();
                $requestBranch->body = $body;
                $requestBranch->url = $url;
                $requestBranch->phase2Url = $url;
                $requestBranch->op = Operation::ACTION;
                $requestBranch->method = 'POST';
                $requestBranch->branchId = $subBranch;
                $requestBranch->branchHeaders = TransContext::getBranchHeaders();
                return $this->api->transRequestBranch($requestBranch);

            case Protocol::GRPC:
                if (!$body instanceof Message) {
                    throw new InvalidArgumentException('$body must be instance of Message');
                }
                $branchRequest = new RequestBranch();
                $branchRequest->grpcArgument = $body;
                $branchRequest->url = $url;
                $branchRequest->phase2Url = $url;
                $branchRequest->op = Operation::ACTION;
                !empty($rpcReply) && $branchRequest->grpcDeserialize = $rpcReply;
                $branchRequest->grpcMetadata = [
                    'dtm-gid' => TransContext::getGid(),
                    'dtm-trans_type' => TransType::XA,
                    'dtm-branch_id' => $subBranch,
                    'dtm-op' => Operation::ACTION,
                    'dtm-dtm' => TransContext::getDtm(),
                    'dtm-phase2_url' => $url,
                    'dtm-url' => $url,
                ];
                return $this->api->transRequestBranch($branchRequest);
            default:
                throw new UnsupportedException('Unsupported protocol');
        }
    }

    /**
     * Start a xa global transaction.
     */
    public function globalTransaction(callable $callback, ?string $gid = null): mixed
    {
        $this->init($gid);
        $this->api->prepare(TransContext::toArray());
        $result = null;
        try {
            $result = $callback();

            if($gid === null){
                $this->api->submit(TransContext::toArray());
            }
        } catch (\Throwable $throwable) {
            $this->api->abort(TransContext::toArray());
            throw $throwable;
        }

        return $result;
    }

    protected function init(?string $gid = null)
    {
        $branchId = '';
        if ($gid === null) {
            $gid = $this->generateGid();
        } else {
            $branchId = TransContext::getBranchId();
        }

        TransContext::init($gid, TransType::XA, $branchId);
        TransContext::setOp(Operation::ACTION);

    }
}
