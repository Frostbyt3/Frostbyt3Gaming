<?php
declare(strict_types=1);

if (!function_exists('fbgPaginationRequestedPage')) {
    function fbgPaginationRequestedPage(string $queryKey = 'page_num'): int
    {
        return max(1, (int)($_GET[$queryKey] ?? 1));
    }
}

if (!function_exists('fbgNormalizePagination')) {
    function fbgNormalizePagination(int $totalRows, ?int $pageNum = null, int $perPage = 25): array
    {
        $perPage = max(1, $perPage);
        $totalRows = max(0, $totalRows);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $pageNum = min(max(1, $pageNum ?? fbgPaginationRequestedPage()), $totalPages);

        return [
            'page' => $pageNum,
            'page_num' => $pageNum,
            'per_page' => $perPage,
            'offset' => ($pageNum - 1) * $perPage,
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
        ];
    }
}

if (!function_exists('fbgPaginationUrl')) {
    function fbgPaginationUrl(int|string $pageNum, array $query = [], array $removeKeys = [], string $queryKey = 'page_num'): string
    {
        $query = $query ?: $_GET;
        $query[$queryKey] = is_int($pageNum) ? max(1, $pageNum) : $pageNum;

        foreach ($removeKeys as $key) {
            unset($query[$key]);
        }

        return './page.php?' . http_build_query($query);
    }
}

if (!function_exists('fbgRenderPagination')) {
    function fbgRenderPagination(array $pagination, string $itemLabel, array $options = []): void
    {
        $pageNum = max(1, (int)($pagination['page_num'] ?? $pagination['page'] ?? 1));
        $totalRows = max(0, (int)($pagination['total_rows'] ?? 0));
        $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
        $query = is_array($options['query'] ?? null) ? $options['query'] : $_GET;
        $removeKeys = is_array($options['remove'] ?? null) ? $options['remove'] : [];
        $queryKey = (string)($options['query_key'] ?? 'page_num');
        $className = trim('fbg-admin-form-actions fbg-pagination-footer ' . (string)($options['class'] ?? ''));
        $pluralLabel = (string)($options['plural_label'] ?? ($itemLabel . 's'));
        $label = $totalRows === 1 ? $itemLabel : $pluralLabel;
        $pageWindow = max(1, (int)($options['page_window'] ?? 2));

        $pageNumbers = [];
        $windowStart = max(1, $pageNum - $pageWindow);
        $windowEnd = min($totalPages, $pageNum + $pageWindow);

        foreach ([1, $totalPages] as $edgePage) {
            if ($edgePage >= 1 && $edgePage <= $totalPages) {
                $pageNumbers[$edgePage] = $edgePage;
            }
        }

        for ($page = $windowStart; $page <= $windowEnd; $page++) {
            $pageNumbers[$page] = $page;
        }

        ksort($pageNumbers);
        $lastRenderedPage = 0;

        ?>
        <div class="<?= htmlspecialchars($className, ENT_QUOTES, 'UTF-8') ?>">
            <span class="fbg-pagination-summary"><?= number_format($totalRows) ?> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> — Page <?= $pageNum ?> of <?= $totalPages ?></span>

            <span class="fbg-pagination-controls">
                <?php if ($pageNum > 1): ?>
                    <a class="btn btn-sm fbg-neutral-button" href="<?= htmlspecialchars(fbgPaginationUrl($pageNum - 1, $query, $removeKeys, $queryKey), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <span class="fbg-pagination-pages" aria-label="Pagination pages">
                        <?php foreach ($pageNumbers as $page): ?>
                            <?php if ($lastRenderedPage > 0 && $page > $lastRenderedPage + 1): ?>
                                <button
                                    type="button"
                                    class="fbg-pagination-ellipsis"
                                    data-fbg-pagination-jump
                                    data-min-page="<?= $lastRenderedPage + 1 ?>"
                                    data-max-page="<?= $page - 1 ?>"
                                    data-total-pages="<?= $totalPages ?>"
                                    data-url-template="<?= htmlspecialchars(fbgPaginationUrl('__PAGE__', $query, $removeKeys, $queryKey), ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="Jump to page between <?= $lastRenderedPage + 1 ?> and <?= $page - 1 ?>"
                                >...</button>
                            <?php endif; ?>

                            <?php if ($page === $pageNum): ?>
                                <span class="fbg-pagination-page is-active" aria-current="page"><?= $page ?></span>
                            <?php else: ?>
                                <a class="fbg-pagination-page" href="<?= htmlspecialchars(fbgPaginationUrl($page, $query, $removeKeys, $queryKey), ENT_QUOTES, 'UTF-8') ?>" aria-label="Go to page <?= $page ?>"><?= $page ?></a>
                            <?php endif; ?>

                            <?php $lastRenderedPage = $page; ?>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>

                <?php if ($pageNum < $totalPages): ?>
                    <a class="btn btn-sm fbg-neutral-button" href="<?= htmlspecialchars(fbgPaginationUrl($pageNum + 1, $query, $removeKeys, $queryKey), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                <?php endif; ?>
            </span>
        </div>
        <?php
    }
}