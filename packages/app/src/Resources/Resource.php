<?php

namespace Filament\Resources;

use Filament\Context;
use Filament\Facades\Filament;
use Filament\Forms\Form;
use Filament\GlobalSearch\Actions\Action;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\RelationManagers\RelationGroup;
use function Filament\Support\get_model_label;
use function Filament\Support\locale_has_pluralization;
use Filament\Tables\Table;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Support\Traits\Macroable;

abstract class Resource
{
    use Macroable {
        Macroable::__call as dynamicMacroCall;
    }

    protected static ?string $breadcrumb = null;

    protected static bool $isDiscovered = true;

    protected static bool $isGloballySearchable = true;

    /**
     * @deprecated Use `$modelLabel` instead.
     */
    protected static ?string $label = null;

    protected static ?string $modelLabel = null;

    protected static ?string $model = null;

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationIcon = null;

    protected static ?string $activeNavigationIcon = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = null;

    protected static ?string $recordRouteKeyName = null;

    protected static bool $shouldRegisterNavigation = true;

    /**
     * @deprecated Use `$pluralModelLabel` instead.
     */
    protected static ?string $pluralLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?string $recordTitleAttribute = null;

    protected static ?string $slug = null;

    /**
     * @var string | array<string>
     */
    protected static string | array $routeMiddleware = [];

    protected static int $globalSearchResultsLimit = 50;

    protected static bool $shouldIgnorePolicies = false;

    public static function form(Form $form): Form
    {
        return $form;
    }

    public static function registerNavigationItems(): void
    {
        if (! static::shouldRegisterNavigation()) {
            return;
        }

        if (! static::canViewAny()) {
            return;
        }

        Filament::getCurrentContext()
            ->navigationItems(static::getNavigationItems());
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $routeBaseName = static::getRouteBaseName();

        return [
            NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->activeIcon(static::getActiveNavigationIcon())
                ->isActiveWhen(fn () => request()->routeIs("{$routeBaseName}.*"))
                ->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())
                ->sort(static::getNavigationSort())
                ->url(static::getNavigationUrl()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    public static function resolveRecordRouteBinding(int | string $key): ?Model
    {
        return app(static::getModel())
            ->resolveRouteBindingQuery(static::getEloquentQuery(), $key, static::getRecordRouteKeyName())
            ->first();
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        if (static::shouldIgnorePolicies()) {
            return true;
        }

        $policy = Gate::getPolicyFor($model = static::getModel());
        $user = Filament::auth()->user();

        if ($policy === null) {
            return true;
        }

        if (! method_exists($policy, $action)) {
            return true;
        }

        return Gate::forUser($user)->check($action, $record ?? $model);
    }

    public static function ignorePolicies(bool $condition = true): void
    {
        static::$shouldIgnorePolicies = $condition;
    }

    public static function shouldIgnorePolicies(): bool
    {
        return static::$shouldIgnorePolicies;
    }

    public static function canViewAny(): bool
    {
        return static::can('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::can('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::can('deleteAny');
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::can('forceDelete', $record);
    }

    public static function canForceDeleteAny(): bool
    {
        return static::can('forceDeleteAny');
    }

    public static function canReorder(): bool
    {
        return static::can('reorder');
    }

    public static function canReplicate(Model $record): bool
    {
        return static::can('replicate', $record);
    }

    public static function canRestore(Model $record): bool
    {
        return static::can('restore', $record);
    }

    public static function canRestoreAny(): bool
    {
        return static::can('restoreAny');
    }

    public static function canGloballySearch(): bool
    {
        return static::$isGloballySearchable && count(static::getGloballySearchableAttributes()) && static::canViewAny();
    }

    public static function canView(Model $record): bool
    {
        return static::can('view', $record);
    }

    public static function getBreadcrumb(): string
    {
        return static::$breadcrumb ?? Str::headline(static::getPluralModelLabel());
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::getModel()::query();

        if ($tenant = Filament::getTenant()) {
            static::scopeEloquentQueryToTenant($query, $tenant);
        }

        return $query;
    }

    public static function scopeEloquentQueryToTenant(Builder $query, Model $tenant): Builder
    {
        return $query->whereBelongsTo($tenant);
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        $titleAttribute = static::getRecordTitleAttribute();

        if ($titleAttribute === null) {
            return [];
        }

        return [$titleAttribute];
    }

    /**
     * @return array<Action>
     */
    public static function getGlobalSearchResultActions(Model $record): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return static::getRecordTitle($record);
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        if (static::hasPage('edit') && static::canEdit($record)) {
            return static::getUrl('edit', ['record' => $record]);
        }

        if (static::hasPage('view') && static::canView($record)) {
            return static::getUrl('view', ['record' => $record]);
        }

        return null;
    }

    public static function getGlobalSearchResultsLimit(): int
    {
        return static::$globalSearchResultsLimit;
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        $query = static::getGlobalSearchEloquentQuery();

        foreach (explode(' ', $search) as $searchWord) {
            $query->where(function (Builder $query) use ($searchWord) {
                $isFirst = true;

                foreach (static::getGloballySearchableAttributes() as $attributes) {
                    static::applyGlobalSearchAttributeConstraint(
                        query: $query,
                        search: $searchWord,
                        searchAttributes: Arr::wrap($attributes),
                        isFirst: $isFirst,
                    );
                }
            });
        }

        return $query
            ->limit(static::getGlobalSearchResultsLimit())
            ->get()
            ->map(function (Model $record): ?GlobalSearchResult {
                $url = static::getGlobalSearchResultUrl($record);

                if (blank($url)) {
                    return null;
                }

                return new GlobalSearchResult(
                    title: static::getGlobalSearchResultTitle($record),
                    url: $url,
                    details: static::getGlobalSearchResultDetails($record),
                    actions: static::getGlobalSearchResultActions($record),
                );
            })
            ->filter();
    }

    /**
     * @deprecated Use `getModelLabel()` instead.
     */
    public static function getLabel(): ?string
    {
        return static::$label;
    }

    public static function getModelLabel(): string
    {
        return static::$modelLabel ?? static::getLabel() ?? get_model_label(static::getModel());
    }

    public static function getModel(): string
    {
        return static::$model ?? (string) str(class_basename(static::class))
            ->beforeLast('Resource')
            ->prepend('App\\Models\\');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [];
    }

    /**
     * @deprecated Use `getPluralModelLabel()` instead.
     */
    public static function getPluralLabel(): ?string
    {
        return static::$pluralLabel;
    }

    public static function getPluralModelLabel(): string
    {
        if (filled($label = static::$pluralModelLabel ?? static::getPluralLabel())) {
            return $label;
        }

        if (locale_has_pluralization()) {
            return Str::plural(static::getModelLabel());
        }

        return static::getModelLabel();
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return static::$recordTitleAttribute;
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record?->getAttribute(static::getRecordTitleAttribute()) ?? static::getModelLabel();
    }

    /**
     * @return array<class-string, RelationGroup>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<class-string>
     */
    public static function getWidgets(): array
    {
        return [];
    }

    public static function getRouteBaseName(?string $context = null): string
    {
        $context ??= Filament::getCurrentContext()->getId();

        $slug = static::getSlug();

        return "filament.{$context}.resources.{$slug}";
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return static::$recordRouteKeyName;
    }

    public static function routes(Context $context): void
    {
        $slug = static::getSlug();

        Route::name("{$slug}.")
            ->prefix($slug)
            ->middleware(static::getRouteMiddleware($context))
            ->group(function () use ($context) {
                foreach (static::getPages() as $name => $page) {
                    $page->registerRoute($context)?->name($name);
                }
            });
    }

    /**
     * @return string | array<string>
     */
    public static function getRouteMiddleware(Context $context): string | array
    {
        return static::$routeMiddleware;
    }

    public static function getEmailVerifiedMiddleware(Context $context): string
    {
        return $context->getEmailVerifiedMiddleware();
    }

    public static function isEmailVerificationRequired(Context $context): bool
    {
        return $context->isEmailVerificationRequired();
    }

    public static function getTenantSubscribedMiddleware(Context $context): string
    {
        return $context->getTenantBillingProvider()->getSubscribedMiddleware();
    }

    public static function isTenantSubscriptionRequired(Context $context): bool
    {
        return $context->isTenantSubscriptionRequired();
    }

    public static function getSlug(): string
    {
        if (filled(static::$slug)) {
            return static::$slug;
        }

        return str(static::class)
            ->whenContains(
                '\\Resources\\',
                fn (Stringable $slug): Stringable => $slug->afterLast('\\Resources\\'),
                fn (Stringable $slug): Stringable => $slug->classBasename(),
            )
            ->beforeLast('Resource')
            ->plural()
            ->explode('\\')
            ->map(fn (string $string) => str($string)->kebab()->slug())
            ->implode('/');
    }

    /**
     * @param  array<mixed>  $parameters
     */
    public static function getUrl(string $name = 'index', array $parameters = [], bool $isAbsolute = true, ?string $context = null, ?Model $tenant = null): string
    {
        $parameters['tenant'] ??= ($tenant ?? Filament::getRoutableTenant());

        $routeBaseName = static::getRouteBaseName(context: $context);

        return route("{$routeBaseName}.{$name}", $parameters, $isAbsolute);
    }

    public static function hasPage(string $page): bool
    {
        return array_key_exists($page, static::getPages());
    }

    public static function hasRecordTitle(): bool
    {
        return static::getRecordTitleAttribute() !== null;
    }

    /**
     * @param  array<string>  $searchAttributes
     */
    protected static function applyGlobalSearchAttributeConstraint(Builder $query, string $search, array $searchAttributes, bool &$isFirst): Builder
    {
        /** @var Connection $databaseConnection */
        $databaseConnection = $query->getConnection();

        $searchOperator = match ($databaseConnection->getDriverName()) {
            'pgsql' => 'ilike',
            default => 'like',
        };

        $model = $query->getModel();

        foreach ($searchAttributes as $searchAttribute) {
            $whereClause = $isFirst ? 'where' : 'orWhere';

            $query->when(
                method_exists($model, 'isTranslatableAttribute') && $model->isTranslatableAttribute($searchAttribute),
                function (Builder $query) use ($databaseConnection, $searchAttribute, $searchOperator, $search, $whereClause): Builder {
                    $searchColumn = match ($databaseConnection->getDriverName()) {
                        'pgsql' => "{$searchAttribute}::text",
                        default => "json_extract({$searchAttribute}, '$')",
                    };

                    return $query->{"{$whereClause}Raw"}(
                        "lower({$searchColumn}) {$searchOperator} lower(?)",
                        "%{$search}%",
                    );
                },
                fn (Builder $query): Builder => $query->when(
                    str($searchAttribute)->contains('.'),
                    fn ($query) => $query->{"{$whereClause}Relation"}(
                        (string) str($searchAttribute)->beforeLast('.'),
                        (string) str($searchAttribute)->afterLast('.'),
                        $searchOperator,
                        "%{$search}%",
                    ),
                    fn ($query) => $query->{$whereClause}(
                        $searchAttribute,
                        $searchOperator,
                        "%{$search}%",
                    ),
                ),
            );

            $isFirst = false;
        }

        return $query;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$navigationGroup;
    }

    public static function navigationGroup(?string $group): void
    {
        static::$navigationGroup = $group;
    }

    public static function getNavigationIcon(): string
    {
        return static::$navigationIcon ?? 'heroicon-o-rectangle-stack';
    }

    public static function navigationIcon(?string $icon): void
    {
        static::$navigationIcon = $icon;
    }

    public static function getActiveNavigationIcon(): string
    {
        return static::$activeNavigationIcon ?? static::getNavigationIcon();
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? Str::headline(static::getPluralModelLabel());
    }

    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return null;
    }

    public static function getNavigationSort(): ?int
    {
        return static::$navigationSort;
    }

    public static function navigationSort(?int $sort): void
    {
        static::$navigationSort = $sort;
    }

    public static function getNavigationUrl(): string
    {
        return static::getUrl();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::$shouldRegisterNavigation;
    }

    public static function isDiscovered(): bool
    {
        return static::$isDiscovered;
    }
}