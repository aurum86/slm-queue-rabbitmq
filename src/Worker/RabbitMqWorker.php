<?php

namespace SlmQueueRabbitMq\Worker;

use Psr\Log\LoggerInterface;
use SlmQueue\Job\JobInterface;
use SlmQueue\Queue\QueueInterface;
use SlmQueue\Worker\AbstractWorker;
use SlmQueue\Worker\Event\ProcessJobEvent;
use SlmQueueRabbitMq\Job\MessageRetryCounter;
use SlmQueueRabbitMq\Queue\RabbitMqQueueInterface;
use Zend\EventManager\EventManagerInterface;
use Throwable;
use Exception;

class RabbitMqWorker extends AbstractWorker
{
    /** @var MessageRetryCounter */
    private $retryCounter;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param EventManagerInterface $eventManager
     * @param MessageRetryCounter $retryCounter
     * @param LoggerInterface $logger
     */
    public function __construct(
        EventManagerInterface $eventManager,
        MessageRetryCounter $retryCounter,
        LoggerInterface $logger
    )
    {
        parent::__construct($eventManager);

        $this->retryCounter = $retryCounter;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     * @return int|null Status of the job
     */
    public function processJob(JobInterface $job, QueueInterface $queue)
    {
        if (!$queue instanceof RabbitMqQueueInterface) {
            return null;
        }

        try {
            $job->execute();
            $queue->delete($job);

            return ProcessJobEvent::JOB_STATUS_SUCCESS;
        } catch (Throwable $exception) {
            if ($this->retryCounter->canRetry($job, $queue->getName())) {
                $queue->bury($job);
                $this->logger->warning($exception->getMessage(), $this->createExceptionParams($exception));

                return ProcessJobEvent::JOB_STATUS_FAILURE_RECOVERABLE;
            }

            $queue->delete($job);
            $this->logger->error($exception->getMessage(), $this->createExceptionParams($exception));

            return ProcessJobEvent::JOB_STATUS_FAILURE;
        }
    }

    /**
     * @param Throwable $exception
     * @return array
     */
    public function createExceptionParams(Throwable $exception): array
    {
        return [
            'exception' => new Exception($exception->getMessage(), $exception->getCode(), $exception),
            'stack_trace' => $exception->getTraceAsString()
        ];
    }
}
