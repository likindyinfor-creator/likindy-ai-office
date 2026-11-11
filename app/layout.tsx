import type { Metadata } from "next";
import "./globals.css";
import { AuthProvider } from "./auth/AuthProvider";

export const metadata: Metadata = {
  title: "Likindy AI Office",
  description: "Your Intelligent Office Assistant"
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body><AuthProvider>{children}</AuthProvider></body>
    </html>
  );
}
