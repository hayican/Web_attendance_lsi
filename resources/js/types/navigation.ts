import type { LinkComponentBaseProps } from '@inertiajs/core';
import type { Component } from 'svelte';

type NavIcon = Component<{ class?: string }>;

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<LinkComponentBaseProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<LinkComponentBaseProps['href']>;
    icon?: NavIcon;
    isActive?: boolean;
};
