<?php if (!defined('DAN_PORTAL')) { http_response_code(403); exit; } ?>
<?php if ($monthlyReview): ?>
<section class="panel monthly-review" id="monthly-review" aria-labelledby="monthly-review-heading">
    <div class="section-heading"><div><div class="eyebrow"><?= escape(month_label($month)) ?> · AI REVIEW</div><h2 id="monthly-review-heading"><?= $monthlyReview['status'] === 'completed' ? escape($monthlyReview['result']['headline']) : 'Your monthly review' ?></h2></div><?php if (count($accounts) > 1): ?><span class="pill">All cards</span><?php endif; ?></div>
    <?php if ($monthlyReview['status'] === 'completed'): $review = $monthlyReview['result']; ?>
    <?php if ($monthlyReview['stale']): ?><p class="notice">Your data, categories, or budget changed after this review was saved. This is the original review; it has not been regenerated.</p><?php endif; ?>
    <p class="review-summary"><?= escape(month_review_teaser($review['summary'])) ?></p>
    <details class="review-disclosure" id="monthly-review-details">
    <summary><span class="review-read-more">Read more</span><span class="review-read-less">Read less</span><span class="sr-only"> about <?= escape(month_label($month)) ?> spending</span></summary>
    <?php if ($monthlyReview['coverage'] === 'imported_transactions_only'): ?><p class="hint">Based on transactions imported when this review was prepared; later imports may change the picture.</p><?php endif; ?>
    <?php if (!$monthlyReview['budget_included']): ?><p class="hint">This saved review did not include a budget. It is kept as originally generated; no new AI request is made.</p><?php endif; ?>
    <?php if (month_review_teaser($review['summary']) !== $review['summary']): ?><p class="review-summary"><?= escape($review['summary']) ?></p><?php endif; ?>
    <div class="review-highlights"><div><span class="review-label-positive">What went well</span><p><?= escape($review['bright_spot']) ?></p></div><div><span class="review-label-opportunity">Worth a closer look</span><p><?= escape($review['opportunity']) ?></p></div></div>
    <h3>A few things to try</h3><ul class="review-next-steps"><?php foreach ($review['next_steps'] as $step): ?><li><?= escape($step) ?></li><?php endforeach; ?></ul>
    <p class="hint">Saved <?= escape(substr($monthlyReview['completed_at'], 0, 10)) ?> · <?= (int) $monthlyReview['baseline_count'] ?> earlier complete month<?= $monthlyReview['baseline_count'] === 1 ? '' : 's' ?> compared · AI-generated</p>
    </details>
    <?php elseif ($monthlyReview['status'] === 'failed'): ?>
    <p class="hint">The review couldn’t be saved. It will not retry automatically.</p><form method="post" action="/?page=analyzer"><?php csrf_field(); ?><input type="hidden" name="action" value="retry_month_review"><input type="hidden" name="review_month" value="<?= escape($month) ?>"><p class="hint">Retrying sends a new request to OpenAI.</p><button class="secondary" type="submit">Retry review</button></form>
    <?php else: ?><p class="hint">The review will appear once prepared and saved. <a href="<?= escape(analyzer_url($month)) ?>">Check again</a></p><?php endif; ?>
</section>
<?php endif; ?>
