<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/student-layout.php';
require_once dirname(__DIR__) . '/includes/queries.php';

student_boot();

$category = $_GET['category'] ?? 'all';
$categories = function_exists('gallery_categories') ? gallery_categories() : ['all' => 'All'];
if (!isset($categories[$category])) {
    $category = 'all';
}
$items = class_gallery($category);
student_header('Gallery', 'gallery', lead: 'Class photographs.');
?>

<div class="filters">
    <?php foreach ($categories as $key => $label): ?>
        <a class="chip <?= $category === $key ? 'is-active' : '' ?>" href="?category=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$items): ?>
    <div class="empty-state">
        <h2>No photographs yet</h2>
        <p>Published class photos appear here.</p>
    </div>
<?php else: ?>
    <div class="masonry">
        <?php foreach ($items as $item): ?>
            <a href="<?= e(url($item['image_path'])) ?>" data-lightbox data-caption="<?= e($item['caption'] ?: $item['title']) ?>">
                <img src="<?= e(url($item['image_path'])) ?>" alt="<?= e($item['title']) ?>" loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php student_footer('gallery'); ?>
