<?php

namespace Frosh\TemplateMail\Subscriber;

use Frosh\TemplateMail\Services\MailFinderService;
use Frosh\TemplateMail\Services\MailFinderServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\DocumentService;
use Shopware\Core\Content\MailTemplate\Exception\MailEventConfigurationException;
use Shopware\Core\Content\MailTemplate\MailTemplateActions;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\MailTemplate\Service\MailServiceInterface;
use Shopware\Core\Content\MailTemplate\Subscriber\MailSendSubscriber as ShopwareMailSenderSubscriber;
use Shopware\Core\Content\MailTemplate\Subscriber\MailSendSubscriberConfig;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Event\BusinessEvent;
use Shopware\Core\Framework\Event\EventData\EventDataType;
use Shopware\Core\Framework\Event\MailActionInterface;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MailSendSubscriber implements EventSubscriberInterface
{
    public const ACTION_NAME = MailTemplateActions::MAIL_TEMPLATE_MAIL_SEND_ACTION;
    public const MAIL_CONFIG_EXTENSION = 'mail-attachments';

    /**
     * @var MailServiceInterface
     */
    private $mailService;

    /**
     * @var EntityRepositoryInterface
     */
    private $mailTemplateRepository;

    /**
     * @var MediaService
     */
    private $mediaService;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    /**
     * @var DocumentService
     */
    private $documentService;

    /**
     * @var EntityRepositoryInterface
     */
    private $documentRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var MailFinderServiceInterface
     */
    private $mailFinderService;

    public function __construct(
        MailServiceInterface $mailService,
        EntityRepositoryInterface $mailTemplateRepository,
        MediaService $mediaService,
        EntityRepositoryInterface $mediaRepository,
        EntityRepositoryInterface $documentRepository,
        DocumentService $documentService,
        LoggerInterface $logger,
        MailFinderServiceInterface $mailFinderService
    ) {
        $this->mailService = $mailService;
        $this->mailTemplateRepository = $mailTemplateRepository;
        $this->mediaService = $mediaService;
        $this->mediaRepository = $mediaRepository;
        $this->documentRepository = $documentRepository;
        $this->documentService = $documentService;
        $this->logger = $logger;
        $this->mailFinderService = $mailFinderService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ShopwareMailSenderSubscriber::ACTION_NAME => 'sendMail'
        ];
    }

    public function sendMail(BusinessEvent $event): void
    {
        $mailEvent = $event->getEvent();

        $extension = $event->getContext()->getExtension(self::MAIL_CONFIG_EXTENSION);
        if (!$extension instanceof MailSendSubscriberConfig) {
            $extension = new MailSendSubscriberConfig(false, [], []);
        }

        if ($extension->skip()) {
            return;
        }

        if (!$mailEvent instanceof MailActionInterface) {
            throw new MailEventConfigurationException('Not a instance of MailActionInterface', get_class($mailEvent));
        }


        if (!$mailEvent instanceof MailActionInterface) {
            throw new MailEventConfigurationException('Not a instance of MailActionInterface', get_class($mailEvent));
        }

        if (!\array_key_exists('mail_template_type_id', $event->getConfig())) {
            throw new MailEventConfigurationException('Configuration mail_template_type_id missing.', get_class($mailEvent));
        }

        $config = $event->getConfig();

        if (!isset($config['mail_template_id'])) {
            return;
        }

        $mailTemplate = $this->getMailTemplate($config['mail_template_id'], $event->getContext());

        if ($mailTemplate === null) {
            return;
        }

        $technicalName = $mailTemplate->getMailTemplateType()->getTechnicalName();

        $html = $this->mailFinderService->findTemplateByTechnicalName(MailFinderService::TYPE_HTML, $technicalName, $event);
        $plain = $this->mailFinderService->findTemplateByTechnicalName(MailFinderService::TYPE_PLAIN, $technicalName, $event);
        $subject = $this->mailFinderService->findTemplateByTechnicalName(MailFinderService::TYPE_SUBJECT, $technicalName, $event);

        $data = new DataBag();
        $recipients = $mailEvent->getMailStruct()->getRecipients();
        if (isset($config['recipients'])) {
            $recipients = $config['recipients'];
        }

        $data->set('recipients', $recipients);
        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $mailEvent->getSalesChannelId());

        $data->set('templateId', $mailTemplate->getId());
        $data->set('customFields', $mailTemplate->getCustomFields());
        $data->set('contentHtml', $html ? $html : $mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain', $plain ? $plain : $mailTemplate->getTranslation('contentPlain'));
        $data->set('subject', $subject ? $subject : $mailTemplate->getTranslation('subject'));
        $data->set('mediaIds', []);

        $attachments = $this->buildAttachments($event, $mailTemplate, $extension);

        if (!empty($attachments)) {
            $data->set('binAttachments', $attachments);
        }

        try {
            $this->mailService->send(
                $data->all(),
                $event->getContext(),
                $this->getTemplateData($mailEvent)
            );

            $writes = array_map(static function ($id) {
                return ['id' => $id, 'sent' => true];
            }, $extension->getDocumentIds());

            if (!empty($writes)) {
                $this->documentRepository->update($writes, $event->getContext());
            }
        } catch (\Exception $e) {
            $this->logger->error(
                "Could not send mail:\n"
                . $e->getMessage() . "\n"
                . 'Error Code:' . $e->getCode() . "\n"
                . "Template data: \n"
                . json_encode($data->all()) . "\n"
            );
        }
    }

    private function getMailTemplate(string $id, Context $context): ?MailTemplateEntity
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('media.media');
        $criteria->addAssociation('mailTemplateType');
        $criteria->setLimit(1);

        return $this->mailTemplateRepository
            ->search($criteria, $context)
            ->first();
    }

    /**
     * @throws MailEventConfigurationException
     */
    private function getTemplateData(MailActionInterface $event): array
    {
        $data = [];
        /* @var EventDataType $item */
        foreach (array_keys($event::getAvailableData()->toArray()) as $key) {
            $getter = 'get' . ucfirst($key);
            if (method_exists($event, $getter)) {
                $data[$key] = $event->$getter();
            } else {
                throw new MailEventConfigurationException('Data for ' . $key . ' not available.', get_class($event));
            }
        }

        return $data;
    }

    private function buildAttachments(BusinessEvent $event, MailTemplateEntity $mailTemplate, MailSendSubscriberConfig $config): array
    {
        $attachments = [];

        if ($mailTemplate->getMedia() !== null) {
            foreach ($mailTemplate->getMedia() as $mailTemplateMedia) {
                if ($mailTemplateMedia->getMedia() === null) {
                    continue;
                }
                if ($mailTemplateMedia->getLanguageId() !== null && $mailTemplateMedia->getLanguageId() !== $event->getContext()->getLanguageId()) {
                    continue;
                }

                $attachments[] = $this->mediaService->getAttachment(
                    $mailTemplateMedia->getMedia(),
                    $event->getContext()
                );
            }
        }

        if (!empty($config->getMediaIds())) {
            $entities = $this->mediaRepository->search(new Criteria($config->getMediaIds()), $event->getContext());

            foreach ($entities as $media) {
                $attachments[] = $this->mediaService->getAttachment($media, $event->getContext());
            }
        }

        if (!empty($config->getDocumentIds())) {
            $criteria = new Criteria($config->getDocumentIds());
            $criteria->addAssociation('documentMediaFile');
            $criteria->addAssociation('documentType');

            $entities = $this->documentRepository->search($criteria, $event->getContext());

            foreach ($entities as $document) {
                $document = $this->documentService->getDocument($document, $event->getContext());

                $attachments[] = [
                    'content' => $document->getFileBlob(),
                    'fileName' => $document->getFilename(),
                    'mimeType' => $document->getContentType(),
                ];
            }
        }

        return $attachments;
    }
}
