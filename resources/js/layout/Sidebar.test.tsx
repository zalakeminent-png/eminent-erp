import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Sidebar } from '@/layout/Sidebar';
import { filterNavigation, navigation } from '@/layout/navigation';
import { makeUser, renderWithAuth } from '@/test/utils';

function navLinks() {
    // Desktop and mobile copies render the same links; inspect the first nav.
    const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];
    return within(nav).queryAllByRole('link', { hidden: true }).map((link) => link.textContent); // includes collapsed menus
}

describe('sidebar navigation', () => {
    it('shows every menu item to a user with all permissions', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, {
            user: makeUser(['dashboard.view', 'company.view', 'users.view', 'roles.view', 'activity-logs.view']),
        });

        expect(navLinks()).toEqual(['Dashboard', 'Company Settings', 'Users', 'Roles & Permissions', 'Activity Logs']);
    });

    it('hides menu items the user is not permitted to access', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, {
            user: makeUser(['dashboard.view', 'company.view']),
        });

        expect(navLinks()).toEqual(['Dashboard', 'Company Settings']);
        expect(screen.queryAllByText('Users')).toHaveLength(0);
        expect(screen.queryAllByText('Activity Logs')).toHaveLength(0);
    });

    it('hides an entire group when none of its items are permitted', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, {
            user: makeUser(['dashboard.view', 'inventory.view']),
        });

        expect(navLinks()).toEqual(['Dashboard']);
        expect(screen.queryAllByText('System Settings')).toHaveLength(0);
    });

    it('opens and closes a main menu submenu on click', async () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, { user: makeUser(['dashboard.view', 'users.view', 'roles.view']) });
        const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];
        const toggle = within(nav).getByRole('button', { name: /System Settings/ });

        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(within(nav).queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();

        await userEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(within(nav).getByRole('link', { name: 'Users' })).toHaveAttribute('href', '/settings/users');

        await userEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(within(nav).queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();
    });

    it('keeps the menu of the current page open and toggles each menu independently', async () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, {
            user: makeUser(['users.view', 'items.view', 'inventory.stock.view']), route: '/settings/users/5',
        });
        const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];

        expect(within(nav).getByRole('button', { name: /System Settings/ })).toHaveAttribute('aria-expanded', 'true');
        expect(within(nav).getByRole('link', { name: 'Users' })).toHaveClass('bg-brand-50'); // active state preserved
        expect(within(nav).getByRole('button', { name: /Master Data/ })).toHaveAttribute('aria-expanded', 'false');

        await userEvent.click(within(nav).getByRole('button', { name: /Inventory/ }));
        expect(within(nav).getByRole('link', { name: 'Stock Overview' })).toBeInTheDocument();
        expect(within(nav).getByRole('link', { name: 'Users' })).toBeInTheDocument();
        expect(within(nav).queryByRole('link', { name: 'Items / Products' })).not.toBeInTheDocument();
    });

    it('filterNavigation keeps only permitted entries', () => {
        const can = (p: string | string[]) => ['users.view'].includes(p as string);
        const result = filterNavigation(navigation, can);

        expect(result).toHaveLength(1);
        expect('children' in result[0] && result[0].children.map((c) => c.label)).toEqual(['Users']);
    });
});
