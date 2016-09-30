#!/usr/bin/php
<?php
/*
 * Async Mail Queue Processing Daemon
 * BB 2014
 */
define('LETTUCE_SERVER_PATH', __DIR__ . '/../src/');
include_once(LETTUCE_SERVER_PATH . '/root/Root.php');
require_once(LETTUCE_SERVER_PATH . '/composer/vendor/autoload.php');
$root = new LettuceRoot();  // Get the root lettuce loader instance so that we can access the core modules.

// Load and configure the message queue
/* @var Queue $queue */
$queue = LettuceGrow::extension('Queue');
$queue->switchDefaultChannel(Queue::CHANNEL_SYSTEM);
$queue->defineQueue(Queue::QUEUE_SYSTEM_MAILER, true);
$queue->prefetchSize(1); // Ensure MQ doesn't pre-allocate more than 1 message to this consumer (for later when more than one is running)

/* @var Mail_SMTP $mail_SMTP */
$mail_SMTP = LettuceGrow::extension('Mail_SMTP');

// Watch the queue
print "Mailer Started...\n";
$queue->fetch(
    Queue::QUEUE_SYSTEM_MAILER,
    function($queue_entry) use ($root, $mail_SMTP) {
        $html = null; $plain = null; $subject = null;
        /* @var QueueEntry $queue_entry */
        $message = $queue_entry->getMessage();

        // Load Subject template (if present)
        if (isset($message['template_subject'])) {
            var_dump($message['template_subject']);
            $subject = $root->view->template($message['template_subject'], Common::getIfSet($message['scope']), true);
        }

        // Load HTML copy of the mail template (if present)
        if (isset($message['template_html'])) {
            $html = $root->view->template($message['template_html'], Common::getIfSet($message['scope']), true);
        }

        // Load Plain Text copy of the mail template (if present)
        if (isset($message['template'])) {
            $plain = $root->view->template($message['template'], Common::getIfSet($message['scope']), true);
        }

        print "Processing...";
        if ($mail_SMTP->send($message['to'], $subject, $plain, $html)) {
            print "Processed Mail message for {$message['to']}\n";
            $queue_entry->ack();
        } else {
            print "Failed to process mail message for {$message['to']}\n";
        }
    },
    true
);

while(true) {
    $queue->wait();
}
