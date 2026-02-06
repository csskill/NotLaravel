/**
 * Example TypeScript file
 * This demonstrates the TypeScript compilation setup
 * 
 * To use: 
 * 1. Create .ts files in resources/js/
 * 2. Run `yarn dev` or `yarn build`
 * 3. Compiled JS will appear in public/js/
 */

// Example interface
interface User {
    id: number;
    name: string;
    email: string;
}

// Example class
class UserManager {
    private users: User[] = [];

    addUser(user: User): void {
        this.users.push(user);
        console.log(`User ${user.name} added successfully`);
    }

    getUser(id: number): User | undefined {
        return this.users.find(u => u.id === id);
    }

    getAllUsers(): User[] {
        return [...this.users];
    }
}

// Example usage
const manager = new UserManager();

// Export for use in other modules
export { User, UserManager };
