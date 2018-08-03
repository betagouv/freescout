<?php

namespace App\Console\Commands;

use App\Conversation;
use App\Customer;
use App\Email;
use App\Events\CustomerReplied;
use App\Mailbox;
use App\Thread;
use App\Mail\Mail;
use Illuminate\Console\Command;
use Webklex\IMAP\Client;

class FetchEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:fetch-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch emails from mailboxes addresses';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Get active mailboxes
        $mailboxes = Mailbox::where('in_protocol', '<>', '')
            ->where('in_server', '<>', '')
            ->where('in_port', '<>', '')
            ->where('in_username', '<>', '')
            ->where('in_password', '<>', '')
            ->get();

            foreach ($mailboxes as $mailbox) {
                $this->info('['.date('Y-m-d H:i:s').'] Mailbox: '.$mailbox->name);
                try {
                    $this->fetch($mailbox);
                } catch(\Exception $e) {
                    $this->error('['.date('Y-m-d H:i:s').'] Error: '.$e->getMessage().'; Line: '.$e->getLine());
                    activity()
                       ->withProperties([
                            'error'    => $e->getMessage(),
                            'mailbox'  => $mailbox->name,
                        ])
                       ->useLog(\App\ActivityLog::NAME_EMAILS_FETCHING)
                       ->log(\App\ActivityLog::DESCRIPTION_EMAILS_FETCHING_ERROR);
                }
            }
    }

    public function fetch($mailbox)
    {
        $client = new Client([
            'host'          => $mailbox->in_server,
            'port'          => $mailbox->in_port,
            'encryption'    => $mailbox->getInEncryptionName(),
            'validate_cert' => true,
            'username'      => $mailbox->in_username,
            'password'      => $mailbox->in_password,
            'protocol'      => $mailbox->getInProtocolName()
        ]);

        // Connect to the Server
        $client->connect();

        // Get folder
        $folder = $client->getFolder('INBOX');

        if (!$folder) {
            throw new \Exception("Could not get mailbox folder: INBOX", 1);
        }

        // Get unseen messages for a period
        $messages = $folder->query()->unseen()->since(now()->subDays(1))->leaveUnread()->get();
        
        $this->line('['.date('Y-m-d H:i:s').'] Fetched: '.count($messages));

        $message_index = 1;
        foreach ($messages as $message_id => $message) {
            $this->line('['.date('Y-m-d H:i:s').'] '.$message_index.') '.$message->getSubject());
            $message_index++;

            // Check if message already fetched
            if (Thread::where('message_id', $message_id)->first()) {
                $this->line('['.date('Y-m-d H:i:s').'] Message with such Message-ID has been fetched before: '.$message_id);
                $message->setFlag(['Seen']);
                continue;
            }
            
            if ($message->hasHTMLBody()) {
                // Get body and replace :cid with images URLs
                $body = $message->getHTMLBody(true);
                $body = $this->separateReply($body, true);
            } else {
                $body = $message->getTextBody();
                $body = $this->separateReply($body, false);
            }
            if (!$body) {
                $this->error('['.date('Y-m-d H:i:s').'] Message body is empty');
                $message->setFlag(['Seen']);
                continue;
            }

            $subject = $message->getSubject();
            $from = $message->getReplyTo();
            if (!$from) {
                $from = $message->getFrom();
            }
            if (!$from) {
                $this->error('['.date('Y-m-d H:i:s').'] From is empty');
                $message->setFlag(['Seen']);
                continue;
            } else {
                $from = $this->formatEmailList($from);
                $from = $from[0];
            }

            $to = $this->formatEmailList($message->getTo());
            $to = $this->removeMailboxEmail($to, $mailbox->email);

            $cc = $this->formatEmailList($message->getCc());
            $cc = $this->removeMailboxEmail($cc, $mailbox->email);

            $bcc = $this->formatEmailList($message->getBcc());
            $bcc = $this->removeMailboxEmail($bcc, $mailbox->email);

            $in_reply_to = $message->getInReplyTo();
            $references = $message->getReferences();

            $attachments = $message->getAttachments();

            $save_result = $this->saveThread($mailbox->id, $message_id, $in_reply_to, $references, $from, $to, $cc, $bcc, $subject, $body, $attachments);
            
            if ($save_result) {
                $message->setFlag(['Seen']);
                $this->line('['.date('Y-m-d H:i:s').'] Processed');
            } else {
                $this->error('['.date('Y-m-d H:i:s').'] Error occured processing message');
            }
        }
    }

    /**
     * Save email as thread.
     */
    public function saveThread($mailbox_id, $message_id, $in_reply_to, $references, $from, $to, $cc, $bcc, $subject, $body, $attachments)
    {
        $cc = array_merge($cc, $to);

        // Find conversation
        $new = false;
        $conversation = null;
        $now = date('Y-m-d H:i:s');
        
        $prev_thread = null;

        if ($in_reply_to) {
            $prev_thread = Thread::where('message_id', $in_reply_to)->first();
        } elseif ($references) {
            if (!is_array($references)) {
                $references = array_filter(preg_split("/[, <>]/", $references));
            }
            $prev_thread = Thread::whereIn('message_id', $references)->first();
        }

        if ($prev_thread) {
            $conversation = $prev_thread->conversation;
        } else {
            // Create conversation
            $new = true;
            $customer = Customer::create($from);

            $conversation = new Conversation();
            $conversation->type = Conversation::TYPE_EMAIL;
            $conversation->status = Conversation::STATUS_ACTIVE;
            $conversation->state = Conversation::STATE_PUBLISHED;
            $conversation->subject = $subject;
            $conversation->setCc($cc);
            $conversation->setBcc($bcc);
            $conversation->setPreview($body);
            if (count($attachments)) {
                $conversation->has_attachments = true;
            }
            $conversation->mailbox_id = $mailbox_id;
            $conversation->customer_id = $customer->id;
            $conversation->created_by_customer_id = $customer->id;
            $conversation->source_via = Conversation::PERSON_CUSTOMER;
            $conversation->source_type = Conversation::SOURCE_TYPE_EMAIL;
        }
        $conversation->last_reply_at = $now;
        $conversation->last_reply_from = Conversation::PERSON_USER;
        // Set folder id
        $conversation->updateFolder();
        $conversation->save();

        // Thread
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->message_id = $message_id;
        $thread->body = $body;
        $thread->setTo($to);
        $thread->setCc($cc);
        $thread->setBcc($bcc);
        $thread->source_via = Thread::PERSON_CUSTOMER;
        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->customer_id = $customer->id;
        $thread->created_by_customer_id = $customer->id;
        $thread->save();

        event(new CustomerReplied($conversation, $thread, $new));

        return true;
    }

    /**
     * Separate reply in the body.
     *
     * @param string $body
     *
     * @return string
     */
    public function separateReply($body, $is_html)
    {
        if ($is_html) {
            $separator = Mail::REPLY_ABOVE_HTML;

            $dom = new \DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML($body);
            libxml_use_internal_errors(false);
            $bodies = $dom->getElementsByTagName('body');
            if ($bodies->length == 1) {
                $body_el = $bodies->item(0);
                $body = $dom->saveHTML($body_el);
            }
            preg_match("/<body[^>]*>(.*?)<\/body>/is", $body, $matches);
            if (count($matches)) {
                $body = $matches[1];
            }
        } else {
            $separator = Mail::REPLY_ABOVE_TEXT;
            $body = nl2br($body);
        }
        $parts = explode($separator, $body);
        if (!empty($parts)) {
            return $parts[0];
        }
        return $body;
    }

    /**
     * Remove mailbox email from the list of emails.
     * 
     * @param  array $list
     * @param  string $mailbox_email [description]
     * @return array
     */
    public function removeMailboxEmail($list, $mailbox_email)
    {
        if (!is_array($list)) {
            return [];
        }
        foreach ($list as $i => $email) {
            if (Email::sanitizeEmail($email) == $mailbox_email) {
                unset($list[$i]);
                break;
            }
        }
        return $list;
    }

    /**
     * Conver email object to plain emails.
     * 
     * @param  array $obj_list
     * @return array
     */
    public function formatEmailList($obj_list)
    {
        $plain_list = [];
        foreach ($obj_list as $item) {
            $plain_list[] = $item->mail;
        }
        return $plain_list;
    }
}