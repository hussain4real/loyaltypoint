<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffRole: string
{
    case Operations = 'operations';
    case Compliance = 'compliance';
    case Finance = 'finance';
    case Support = 'support';
    case PartnerViewer = 'partner_viewer';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Operations, self::Compliance => ['dashboard.read', 'cases.read', 'cases.manage', 'audit.read', 'elevations.manage'],
            self::Finance => ['dashboard.read', 'cases.read', 'cases.manage', 'audit.read'],
            self::Support => ['dashboard.read', 'cases.read', 'cases.manage'],
            self::PartnerViewer => ['dashboard.read', 'cases.read'],
        };
    }
}
