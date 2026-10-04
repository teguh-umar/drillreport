<?php

namespace GlpiPlugin\Drillreport;

use CommonDBTM;
use Session;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Entity checks for the rows that take their entity from a drill.
 *
 * Objective, Action and Drill_Item carry no `entities_id` of their own, so
 * core restricts neither their search lists (SQLProvider restricts only when
 * the itemtype's table has `entities_id`) nor a direct `getFromDB()`. Both go
 * through the parent drill here.
 */
class EntityScope
{
    /** Alias of the drills table in the default join of a child's search. */
    public const ALIAS = 'drillreport_scope_drill';

    private const DRILL_FK = 'plugin_drillreport_drills_id';

    /** @return list<class-string<CommonDBTM>> */
    private static function childItemtypes(): array
    {
        return [Action::class, Objective::class, Drill_Item::class];
    }

    /** Never restrict cron / CLI work: there is no session entity to restrict to. */
    private static function applies(string $itemtype): bool
    {
        return in_array($itemtype, self::childItemtypes(), true)
            && !isCommandLine()
            && !Session::isCron();
    }

    /**
     * Default join of a child's search: its drill, under {@see ALIAS}.
     *
     * @return array<string,mixed> iterator criteria, [] when it does not apply
     */
    public static function defaultJoin(string $itemtype, string $ref_table): array
    {
        if (!self::applies($itemtype)) {
            return [];
        }
        return [
            'LEFT JOIN' => [
                Drill::getTable() . ' AS ' . self::ALIAS => [
                    'ON' => [self::ALIAS => 'id', $ref_table => self::DRILL_FK],
                ],
            ],
        ];
    }

    /**
     * Default where of a child's search: the drill is in the user's entities.
     *
     * @return array<int|string,mixed> iterator criteria, [] when it does not apply
     */
    public static function defaultWhere(string $itemtype): array
    {
        if (!self::applies($itemtype)) {
            return [];
        }
        return getEntitiesRestrictCriteria(self::ALIAS, '', '', true);
    }

    /** Refuse unless the drill exists and the user may open it. */
    public static function checkDrill(int $drills_id): void
    {
        $drill = new Drill();
        if ($drills_id <= 0 || !$drill->getFromDB($drills_id)) {
            throw new NotFoundHttpException();
        }
        if (!$drill->can($drills_id, READ)) {
            throw new AccessDeniedHttpException(
                sprintf('User cannot open drill %d, the parent of the requested row', $drills_id)
            );
        }
    }

    /**
     * Load a child row and refuse unless the user may open its drill. The
     * drill is read from the stored row, never from the request.
     */
    public static function checkChild(CommonDBTM $child, int $id): void
    {
        if ($id <= 0 || !$child->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        self::checkDrill((int) ($child->fields[self::DRILL_FK] ?? 0));
    }
}
