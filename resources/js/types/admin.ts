export type AdminNavItem = {
    label: string;
    href: string;
    icon: string;
    alert?: string;
};

export type AdminNavGroup = {
    label: string;
    items: AdminNavItem[];
};

export type AdminAlert = {
    key: string;
    label: string;
    count: number;
    href: string;
};

export type AdminNotification = {
    id: string;
    kind: string;
    title: string;
    read: boolean;
    at: string | null;
};

export type AdminShell = {
    groups: AdminNavGroup[];
    alerts: AdminAlert[];
    notifications: { unread: number; items: AdminNotification[] };
    role: string;
};

export type PageLink = { url: string | null; label: string; active: boolean };
