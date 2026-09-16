<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/public-layout.php';
require_once __DIR__ . '/includes/queries.php';

$id = request_int('id');
$event = $id ? find_published_event($id) : null;
if (!$event) {
    http_response_code(404);
    public_header(site_title('Event'), 'events');
    page_intro('Events', 'Event not found');
    echo '<section class="section" style="padding-top:0"><div class="container">';
    empty_state('This event is unavailable', 'It may be unpublished, or the link may be incorrect.');
    echo '</div></section>';
    public_footer();
    exit;
}

public_header(site_title($event['title']), 'events', (string) $event['description']);
page_intro('Event', $event['title']);
?>

<section class="section" style="padding-top:0">
    <div class="container split">
        <article class="prose">
            <?php if ($event['cover_image']): ?>
                <div class="media-cover">
                    <img src="<?= e(url($event['cover_image'])) ?>" alt="">
                </div>
            <?php endif; ?>
            <p><?= nl2br(e((string) $event['description'])) ?></p>
        </article>
        <aside class="event-aside">
            <p class="eyebrow">Details</p>
            <p><span class="badge badge-<?= e($event['status']) ?>"><?= e(status_label($event['status'])) ?></span></p>
            <?php if (!empty($event['category'])): ?><p><span class="badge"><?= e(status_label((string) $event['category'])) ?></span></p><?php endif; ?>
            <?php if ($event['event_date']): ?><p><?= icon('calendar', 16) ?> <?= e(format_date($event['event_date'])) ?></p><?php endif; ?>
            <?php if ($event['event_time']): ?><p><?= icon('clock', 16) ?> <?= e($event['event_time']) ?></p><?php endif; ?>
            <?php if ($event['location']): ?><p><?= icon('map-pin', 16) ?> <?= e($event['location']) ?></p><?php endif; ?>
            <p><a href="<?= e(url('index.php#events')) ?>">Back to calendar</a></p>
        </aside>
    </div>
</section>

<?php public_footer(); ?>
