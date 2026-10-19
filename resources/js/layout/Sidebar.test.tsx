import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Sidebar } from '@/layout/Sidebar';
import { filterNavigation, navigation } from '@/layout/navigation';
import { makeUser, renderWithAuth } from '@/test/utils';

function navLinks() {
    // Desktop and mobile copies render the same links; inspect the first nav.
    const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];
    return within(nav).queryAllByRole('link').map((link) => link.textContent);
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

    it('filterNavigation keeps only permitted entries', () => {
        const can = (p: string | string[]) => ['users.view'].includes(p as string);
        const result = filterNavigation(navigation, can);

        expect(result).toHaveLength(1);
        expect('children' in result[0] && result[0].children.map((c) => c.label)).toEqual(['Users']);
    });
});
