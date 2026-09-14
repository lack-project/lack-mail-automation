<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

use Phore\MailClient\Email;
use Phore\MailClient\MailClient;

final class ReplyContactResolver implements ContactResolver
{
    private ?MailClient $client = null;
    private ?AutomationStorage $storage = null;

    public function bind(MailClient $client, AutomationStorage $storage): void
    {
        if ($this->client !== null || $this->storage !== null) { throw new \LogicException('Contact resolver is already bound.'); }
        $this->client = $client;
        $this->storage = $storage;
    }

    public function resolve(Email $mail): ContactResolution
    {
        $client = $this->client ?? throw new \LogicException('Contact resolver is not bound.');
        $storage = $this->storage ?? throw new \LogicException('Contact resolver is not bound.');
        $from = count($mail->from()) === 1 ? $mail->from()[0]->getAddress() : null;
        $known = $from === null ? null : $storage->contacts()->findByEmail($from);

        if ($from === null || ($client->fromAddress() !== null && $from === $client->fromAddress()->getAddress())) {
            return new ContactResolution($known === null ? ContactResolutionStatus::Unknown : ContactResolutionStatus::KnownAddress, $known);
        }

        $replyId = $mail->inReplyTo();
        if ($replyId === null) {
            return new ContactResolution($known === null ? ContactResolutionStatus::Unknown : ContactResolutionStatus::KnownAddress, $known);
        }

        $evidence = $storage->sentEvidence($replyId);
        if ($evidence === null) {
            return new ContactResolution(ContactResolutionStatus::OutgoingMissing, $known);
        }

        try {
            $client->peek($evidence->serverId);
        } catch (\RuntimeException $error) {
            if (str_contains(strtolower($error->getMessage()), 'no longer exists')) {
                return new ContactResolution(ContactResolutionStatus::OutgoingMissing, $known);
            }
            throw $error;
        }

        $recipientContact = $storage->contacts()->findByEmail($evidence->recipientEmail);
        if ($known !== null && $recipientContact !== null && $known->id !== $recipientContact->id) {
            return new ContactResolution(ContactResolutionStatus::Conflict, $known, false, null);
        }
        if ($known !== null && $recipientContact === null && $known->primaryEmail !== strtolower($evidence->recipientEmail)) {
            return new ContactResolution(ContactResolutionStatus::Conflict, $known, false, null);
        }
        if ($known !== null) {
            return new ContactResolution(ContactResolutionStatus::KnownAddress, $known, false, $evidence);
        }

        if ($recipientContact !== null) {
            $recipientContact->addAlias($from, source: 'verified_reply');
            return new ContactResolution(ContactResolutionStatus::AliasAdded, $recipientContact, true, $evidence);
        }

        $created = $storage->contacts()->create($evidence->recipientEmail, source: 'verified_reply');
        $aliasAdded = strtolower($from) !== strtolower($evidence->recipientEmail);
        if ($aliasAdded) { $created->addAlias($from, source: 'verified_reply'); }
        return new ContactResolution(ContactResolutionStatus::ContactCreated, $created, $aliasAdded, $evidence);
    }

    public function learnAliasFromReply(Email $mail): ContactResolution
    { return $this->resolve($mail); }
}
