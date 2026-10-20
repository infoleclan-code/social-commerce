import { Text } from 'react-native';

type Props = {
  children: string;
};

export function Eyebrow({ children }: Props) {
  return (
    <Text className="text-[11px] font-semibold uppercase tracking-[1.5px] text-gold">{children}</Text>
  );
}
