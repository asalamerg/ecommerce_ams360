# Inventory adjustments

Product creation starts with zero available stock. Catalog forms may display the
current balance but cannot change it; inventory managers use the dedicated
inventory screen to add or remove stock and must provide a reason.

Each adjustment runs in a database transaction, locks the product row, checks
that the resulting unsigned quantity remains within its supported range, then
updates the product and inserts a `stock_movements` row with the actor, signed
quantity delta, before/after balances, and reason. A failed validation rolls
back both writes. This protects concurrent manual adjustments on databases
that implement row-level locks.

Inventory access is limited to owners, administrators, and inventory managers.
Product managers cannot use catalog requests to bypass that boundary. Stock
reservations, warehouses, and order-driven inventory changes remain future
work and must use the same ledger and locking rules.
