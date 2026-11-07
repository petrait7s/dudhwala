<?php

return [
    'title' => 'Dudhwala admin',
    'login' => 'Sign in', 'email' => 'Email', 'password' => 'Password', 'logout' => 'Sign out',
    'bad_login' => 'Wrong email or password, or the account is locked.',
    'mfa_title' => 'Two-factor code', 'mfa_code' => '6-digit code', 'mfa_recovery' => 'Or a recovery code', 'mfa_bad' => 'That code is not valid.', 'verify' => 'Verify',
    'sec_title' => 'Set up two-factor sign-in', 'sec_intro' => 'Admins must use an authenticator app. Scan the QR code, then enter the 6-digit code.',
    'sec_secret' => 'Or type this key', 'sec_confirm' => 'Confirm', 'sec_codes' => 'Save these recovery codes now. They are shown once.', 'sec_done' => 'I saved them',
    'orders' => 'Orders', 'order' => 'Order', 'filter_status' => 'Status', 'all' => 'All', 'search' => 'Order number or phone',
    'method' => 'Method', 'zone' => 'Area', 'status' => 'Status', 'payment' => 'Payment', 'total' => 'Total', 'placed_at' => 'Placed', 'customer' => 'Customer', 'phone' => 'Phone',
    'address' => 'Address', 'pickup_at' => 'Pickup time', 'outlet' => 'Outlet', 'items' => 'Items', 'subtotal' => 'Subtotal', 'fee' => 'Delivery fee', 'due' => 'Due',
    'timeline' => 'History', 'no_orders' => 'No orders yet.', 'back' => 'Back to orders', 'view_only' => 'View only: managers cannot change orders.',
    'move_to' => 'Move to', 'cancel' => 'Cancel order', 'cancel_reason' => 'Reason (required)', 'confirm_cancel' => 'Confirm cancel',
    'cod_collected' => 'Cash collected', 'verify_payment' => 'Verify TrxID', 'void_payment' => 'Void payment', 'void_reason' => 'Reason (required)', 'trx' => 'TrxID', 'sender' => 'Sender',
    'prior_cancels' => 'Warning: this phone has :n earlier cancelled order(s).', 'refund_flag' => 'Cancelled after payment: refund needed.',
    'bulk_unapproved' => 'Showroom limit was not approved by the owner when this order was placed.',
    'saved' => 'Saved.', 'error' => 'Could not do that: :msg',
    'm' => ['HOME_DELIVERY' => 'Home delivery', 'EXPRESS_PICKUP' => 'Express pickup', 'BULK_ORDER' => 'Showroom'],
    'z' => ['IN_CITY' => 'In Sylhet', 'OUTER_CITY' => 'Outer Sylhet'],
    's' => ['PLACED' => 'Placed', 'PICKUP_REQUESTED' => 'Pickup requested', 'PREPARING' => 'Preparing', 'OUT_FOR_DELIVERY' => 'Out for delivery', 'READY_FOR_PICKUP' => 'Ready for pickup', 'READY_FOR_COLLECTION' => 'Ready for collection', 'DELIVERED' => 'Delivered', 'PICKED_UP' => 'Picked up', 'COLLECTED' => 'Collected', 'CANCELLED' => 'Cancelled'],
    'p' => ['UNPAID' => 'Unpaid', 'PENDING_VERIFICATION' => 'Needs verification', 'PAID' => 'Paid', 'VOID' => 'Void'],
];
