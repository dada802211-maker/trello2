import api from '../../lib/api';
import { User } from '../users/user.entity';

export const authRepository = {
  async signup(
    name: string,
    email: string,
    password: string
  ): Promise<{ user: User }> {
    const result = await api.post('/auth/signup', { name, email, password });
    return { user: new User(result.data.user) };
  },
  async signin(
    email: string,
    password: string
  ): Promise<{ user: User }> {
    const result = await api.post('/auth/signin', { email, password });
    return { user: new User(result.data.user) };
  },
  async signout(): Promise<void> {
    await api.post('/auth/signout');
  },
  async getCurrentUser(): Promise<User | undefined> {
    const result = await api.get('/auth/me');
    if (result.data == null) return;

    return new User(result.data);
  },
};
