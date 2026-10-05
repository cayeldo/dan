<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($monthlyReview): ?>
<section class="panel monthly-review" id="monthly-review" aria-labelledby="monthly-review-heading">
    <div class="section-heading"><div><div class="eyebrow"><?= escape(month_label($month)) ?> · AI REVIEW</div><h2 id="monthly-review-heading"><?= $monthlyReview['status'] === 'completed' ? escape($monthlyReview['result']['headline']) : 'Your monthly review' ?></h2></div><span class="pill">All imported cards</span></div>
    <?php if ($monthlyReview['status'] === 'completed'): $review = $monthlyReview['result']; ?>
    <?php if ($monthlyReview['stale']): ?><p class="notice">Your data, categories, or budget changed after this review was saved. This is the original review; it has not been regenerated.</p><?php endif; ?>
    <?php if (!$monthlyReview['budget_included']): ?><p class="hint">This saved review did not include a budget. It is kept as originally generated; no new AI request is made.</p><?php endif; ?>
    <p class="review-summary"><?= escape($review['summary']) ?></p>
    <div class="review-highlights"><div><span class="review-label-positive">↘ Bright spot</span><p><?= escape($review['bright_spot']) ?></p></div><div><span class="review-label-opportunity">↗ Room to adjust</span><p><?= escape($review['opportunity']) ?></p></div></div>
    <h3>Try next month</h3><ul class="review-next-steps"><?php foreach ($review['next_steps'] as $step): ?><li><?= escape($step) ?></li><?php endforeach; ?></ul>
    <p class="hint">Saved <?= escape(substr($monthlyReview['completed_at'], 0, 10)) ?> · <?= (int) $monthlyReview['baseline_count'] ?> earlier complete month<?= $monthlyReview['baseline_count'] === 1 ? '' : 's' ?> compared · AI-generated</p>
    <?php elseif ($monthlyReview['status'] === 'failed'): ?>
    <p class="hint">The review couldn’t be saved. It will not retry automatically.</p><form method="post" action="/?page=analyzer"><?php csrf_field(); ?><input type="hidden" name="action" value="retry_month_review"><input type="hidden" name="review_month" value="<?= escape($month) ?>"><p class="hint">Retrying sends a new request to OpenAI.</p><button class="secondary" type="submit">Retry review</button></form>
    <?php else: ?><p class="hint">Your month is complete. The review will appear once prepared and saved. <a href="<?= escape(analyzer_url($month)) ?>">Check again</a></p><?php endif; ?>
</section>
<?php endif; ?>
