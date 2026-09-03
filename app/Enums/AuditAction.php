<?php

namespace App\Enums;

enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Login = 'login';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';
    case PlaceBet = 'place_bet';
    case CancelBet = 'cancel_bet';
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case Payout = 'payout';
    case Refund = 'refund';
    case Reversal = 'reversal';
    case RoleAssign = 'role_assign';
    case RoleRevoke = 'role_revoke';
    case PermissionGrant = 'permission_grant';
    case PermissionRevoke = 'permission_revoke';
    case ConfigChange = 'config_change';
    case SecurityAlert = 'security_alert';
    case DataExport = 'data_export';
    case DataImport = 'data_import';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Create',
            self::Update => 'Update',
            self::Delete => 'Delete',
            self::Login => 'Login',
            self::Logout => 'Logout',
            self::LoginFailed => 'Login Failed',
            self::PlaceBet => 'Place Bet',
            self::CancelBet => 'Cancel Bet',
            self::Deposit => 'Deposit',
            self::Withdraw => 'Withdraw',
            self::Payout => 'Payout',
            self::Refund => 'Refund',
            self::Reversal => 'Reversal',
            self::RoleAssign => 'Role Assigned',
            self::RoleRevoke => 'Role Revoked',
            self::PermissionGrant => 'Permission Granted',
            self::PermissionRevoke => 'Permission Revoked',
            self::ConfigChange => 'Configuration Changed',
            self::SecurityAlert => 'Security Alert',
            self::DataExport => 'Data Export',
            self::DataImport => 'Data Import',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::Create, self::Update, self::Delete => 'data',
            self::Login, self::Logout, self::LoginFailed => 'auth',
            self::PlaceBet, self::CancelBet => 'lottery',
            self::Deposit, self::Withdraw, self::Payout, self::Refund, self::Reversal => 'finance',
            self::RoleAssign, self::RoleRevoke, self::PermissionGrant, self::PermissionRevoke => 'access',
            self::ConfigChange => 'system',
            self::SecurityAlert => 'security',
            self::DataExport, self::DataImport => 'data',
        };
    }

    public function severity(): string
    {
        return match ($this) {
            self::LoginFailed, self::SecurityAlert, self::Delete => 'high',
            self::RoleAssign, self::RoleRevoke, self::PermissionGrant, self::PermissionRevoke, self::ConfigChange => 'medium',
            default => 'low',
        };
    }
}
