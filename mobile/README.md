# Mobile

Expo app for the social commerce client. Routes live in `app/`. Feature UI lives in `src/features/`. Server data goes through TanStack Query in `src/api/`. UI-only state lives in Zustand under `src/stores/`.

```bash
npm install
npx expo start
```

The interface is dark-only (NativeWind + a locked color scheme). Auth tokens belong in `expo-secure-store` via `src/lib/auth-token.ts`.
