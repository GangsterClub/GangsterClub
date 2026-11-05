<?php

declare(strict_types=1);

namespace src\Identity\Recovery\Application;

enum RecoveryOutcome: string
{
    case UNAUTHENTICATED = 'unauthenticated';
    case ACTION_UNAVAILABLE = 'action_unavailable';
    case AUTHENTICATOR_ALREADY_ENABLED = 'authenticator_already_enabled';
    case LOST_RECOVERY_UNAVAILABLE = 'lost_recovery_unavailable';
    case START_RATE_LIMITED = 'start_rate_limited';
    case FLOW_RATE_LIMITED = 'flow_rate_limited';
    case EMAIL_SEND_FAILED = 'email_send_failed';
    case EMAIL_VERIFICATION_PENDING = 'email_verification_pending';
    case REAUTHENTICATION_PENDING = 'reauthentication_pending';
    case FLOW_UNAVAILABLE = 'flow_unavailable';
    case FLOW_EXPIRED = 'flow_expired';
    case CHALLENGE_EXPIRED = 'challenge_expired';
    case PENDING_SECRET_MISSING = 'pending_secret_missing';
    case SHOW_FLOW = 'show_flow';
    case INVALID_ACTION = 'invalid_action';
    case EMAIL_CODE_INVALID = 'email_code_invalid';
    case ENTER_RECOVERY_CODE = 'enter_recovery_code';
    case CONFIGURE_AUTHENTICATOR = 'configure_authenticator';
    case AUTHENTICATOR_CODE_INVALID = 'authenticator_code_invalid';
    case REPLACEMENT_WARNING = 'replacement_warning';
    case LOST_REPLACEMENT_WARNING = 'lost_replacement_warning';
    case RECOVERY_CODE_INVALID = 'recovery_code_invalid';
    case CONFIRM_REPLACEMENT_REQUIRED = 'confirm_replacement_required';
    case CONFIGURE_NEW_AUTHENTICATOR = 'configure_new_authenticator';
    case REAUTHENTICATION_EXPIRED = 'reauthentication_expired';
    case CODES_GENERATED = 'codes_generated';
    case ACKNOWLEDGEMENT_REQUIRED = 'acknowledgement_required';
    case CODES_DISPLAY_UNAVAILABLE = 'codes_display_unavailable';
    case ACTIVATION_FAILED = 'activation_failed';
    case COMPLETED = 'completed';
    case PREVIOUS_CODES_INVALID = 'previous_codes_invalid';
    case CODES_REGENERATED = 'codes_regenerated';
    case CANCELLED = 'cancelled';
}
