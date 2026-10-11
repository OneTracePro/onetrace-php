<?php

declare(strict_types=1);

namespace OneTrace\Resource;

use OneTrace\Page;

/**
 * Analytics: reports of every kind, saved reports and dashboards. Running reports needs a secret key with
 * analytics.read (30 report calculations per minute per key); saving reports and dashboards needs analytics.write.
 *
 * A definition is the same JSON the analytics pages use; the response holds the normalized "definition" and the
 * result. Periods are ['last' => 30] or ['from' => 'Y-m-d', 'to' => 'Y-m-d'] in the project timezone.
 */
final class Analytics extends Resource
{
    /**
     * Event report: series of an event and a metric with filters, breakdown, interval and comparison.
     *
     * @param array<string, mixed> $definition series, profiles, breakdown, interval, period, compare
     *
     * @return array<string, mixed>
     */
    public function report(array $definition): array
    {
        return $this->run('reports', $definition);
    }

    /**
     * Funnel: conversion between steps, time between steps, breakdown by the first step.
     *
     * @param array<string, mixed> $definition steps, window, order, profiles, breakdown, period
     *
     * @return array<string, mixed>
     */
    public function funnel(array $definition): array
    {
        return $this->run('funnels', $definition);
    }

    /**
     * Retention by cohorts of the start event.
     *
     * @param array<string, mixed> $definition start, return, cohort, interval, periods, returns, profiles, period
     *
     * @return array<string, mixed>
     */
    public function retention(array $definition): array
    {
        return $this->run('retention', $definition);
    }

    /**
     * Frequent paths after or before the anchor event.
     *
     * @param array<string, mixed> $definition event, filters, direction, steps, gap_minutes, exclude, profiles, period
     *
     * @return array<string, mixed>
     */
    public function paths(array $definition): array
    {
        return $this->run('paths', $definition);
    }

    /**
     * Revenue, orders, buyers, LTV by cohorts, top products and categories.
     *
     * @param array<string, mixed> $definition event, amount, product_property, interval, ltv_interval, ltv_periods, profiles, period
     *
     * @return array<string, mixed>
     */
    public function revenue(array $definition = []): array
    {
        return $this->run('revenue', $definition);
    }

    /**
     * Website visits by channel, source, campaign, referring site or landing page.
     *
     * @param array<string, mixed> $definition dimension, interval, event, amount, profiles, period
     *
     * @return array<string, mixed>
     */
    public function traffic(array $definition = []): array
    {
        return $this->run('traffic', $definition);
    }

    /**
     * Revenue attributed to clicks on campaigns, journeys, messages, recommendations and search.
     *
     * @param array<string, mixed> $definition window_days, model, interval, event, amount, profiles, period
     *
     * @return array<string, mixed>
     */
    public function attribution(array $definition = []): array
    {
        return $this->run('attribution', $definition);
    }

    /**
     * @param array<string, mixed> $params type, limit (1–200, 50 by default), cursor
     */
    public function savedReports(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/analytics/saved-reports', ['query' => $params]));
    }

    /**
     * All saved reports across pages.
     *
     * @param array<string, mixed> $params type, limit
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateSavedReports(array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($params): Page {
            return $this->savedReports(self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * The saved report with its definition.
     *
     * @return array<string, mixed>
     */
    public function savedReport(int $report): array
    {
        return $this->requester->request('GET', $this->path('/analytics/saved-reports/{id}', ['id' => $report]));
    }

    /**
     * Saves a report: ['name' => 'Purchase funnel', 'type' => 'funnel', 'definition' => [...]].
     *
     * @param array<string, mixed> $report         name, type (report, funnel, retention, paths, revenue, traffic, attribution), definition, view
     * @param string|null          $idempotencyKey a repeat with the same key returns the same report; generated if null
     *
     * @return array<string, mixed>
     */
    public function createSavedReport(array $report, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/analytics/saved-reports', ['json' => $report, 'idempotent' => true, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * Partial update: name, definition, view. The type cannot be changed.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function updateSavedReport(int $report, array $changes): array
    {
        return $this->requester->request('PATCH', $this->path('/analytics/saved-reports/{id}', ['id' => $report]), ['json' => $changes]);
    }

    public function deleteSavedReport(int $report): void
    {
        $this->requester->request('DELETE', $this->path('/analytics/saved-reports/{id}', ['id' => $report]));
    }

    /**
     * Runs a saved report; $period replaces the period of its definition.
     *
     * @param array<string, mixed>|null $period ['last' => 7] or ['from' => '2026-10-01', 'to' => '2026-10-07']
     *
     * @return array<string, mixed>
     */
    public function runSavedReport(int $report, ?array $period = null): array
    {
        return $this->requester->request('POST', $this->path('/analytics/saved-reports/{id}/result', ['id' => $report]), ['json' => self::filled(['period' => $period])]);
    }

    /**
     * @param array<string, mixed> $params limit (1–200, 50 by default), cursor
     */
    public function dashboards(array $params = []): Page
    {
        return Page::fromResponse($this->requester->request('GET', '/analytics/dashboards', ['query' => $params]));
    }

    /**
     * All dashboards across pages.
     *
     * @param array<string, mixed> $params limit
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateDashboards(array $params = []): \Generator
    {
        return $this->paginate(function (?string $cursor) use ($params): Page {
            return $this->dashboards(self::filled($params + ['cursor' => $cursor]));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $dashboard): array
    {
        return $this->requester->request('GET', $this->path('/analytics/dashboards/{id}', ['id' => $dashboard]));
    }

    /**
     * Creates a dashboard: ['name' => 'Weekly', 'layout' => [['report_id' => 12, 'size' => 'full']], 'period' => ['last' => 7]].
     *
     * @param array<string, mixed> $dashboard      name, layout, period
     * @param string|null          $idempotencyKey a repeat with the same key returns the same dashboard; generated if null
     *
     * @return array<string, mixed>
     */
    public function createDashboard(array $dashboard, ?string $idempotencyKey = null): array
    {
        return $this->requester->request('POST', '/analytics/dashboards', ['json' => $dashboard, 'idempotent' => true, 'idempotency_key' => $idempotencyKey]);
    }

    /**
     * Partial update: name, layout (replaces the whole layout), period.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function updateDashboard(int $dashboard, array $changes): array
    {
        return $this->requester->request('PATCH', $this->path('/analytics/dashboards/{id}', ['id' => $dashboard]), ['json' => $changes]);
    }

    public function deleteDashboard(int $dashboard): void
    {
        $this->requester->request('DELETE', $this->path('/analytics/dashboards/{id}', ['id' => $dashboard]));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function run(string $kind, array $definition): array
    {
        // An empty definition takes the defaults of the project (purchase event, last 30 days).
        return $this->requester->request('POST', '/analytics/' . $kind, ['json' => $definition]);
    }
}
