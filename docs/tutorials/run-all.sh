#!/bin/sh
# Fresh demo data (reset.sh), then every tutorial into public/tutorials, in an order where each finds what the ones
# before it made. $APP / $PHP as in reset.sh; the app must be served at $BASE (default http://localhost:8000).
cd "$(dirname "$0")" || exit 1
./reset.sh || exit 1
export NODE_PATH=$(npm root -g)
export TINKER="${PHP:-php} artisan tinker"
OUT=${OUT:-../../public/tutorials}
for id in menu-items tables-qr order-flow-settings pos-sale order-board kitchen-display guest-qr-order callcenter-order \
          reservations guest-desk rewards campaigns coupons offers corporate-contract company-visits redeem-voucher reports \
          inventory-recipes purchases inventory-count users-roles daily-digest; do
    OUT=$OUT node record.cjs scenarios/$id.cjs
done
